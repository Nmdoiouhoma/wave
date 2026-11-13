<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Activity\ActivityJournal;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\FailureReason;
use App\Media\PostMediaFailure;
use App\Message\PurgeActivityEvents;
use App\Message\SendPasswordResetEmail;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/** W-70 (ADR 013, DATA_MODEL §15): journal write path, admin console, purge, suspend guard. */
final class ActivityJournalTest extends WaveApiTestCase
{
    private const array MOBILE = ['X-Wave-Client' => 'mobile'];
    private const string UA = 'WaveTest/1.0 (journal)';

    public function testAuthFlowsAreJournaledWithoutAnyEmailTokenOrPassword(): void
    {
        $email = 'Journal.Secret@Example.test';
        $password = 'a very secret passphrase';
        $headers = ['User-Agent' => self::UA];

        $r = $this->api('POST', '/api/v1/auth/register', null, ['headers' => $headers + self::MOBILE, 'json' => ['email' => $email, 'password' => $password, 'username' => 'journaler', 'displayName' => 'J']]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
        $tokens = [(string) $this->json($r)['refreshToken'], (string) $this->json($r)['accessToken']];
        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'journaler']);
        self::assertNotNull($user);
        $id = $user->getId()->toRfc4122();
        self::assertNull($user->getLastLoginAt(), 'registration is not a login');

        self::assertSame(401, $this->api('POST', '/api/v1/auth/login', null, ['headers' => $headers, 'json' => ['email' => $email, 'password' => 'wrong '.$password]])->getStatusCode());
        self::assertSame(401, $this->api('POST', '/api/v1/auth/login', null, ['headers' => $headers, 'json' => ['email' => 'nobody-'.$email, 'password' => $password]])->getStatusCode());
        $login = $this->api('POST', '/api/v1/auth/login', null, ['headers' => $headers + self::MOBILE, 'json' => ['email' => $email, 'password' => $password]]);
        self::assertSame(200, $login->getStatusCode(), $login->getContent(false));
        $first = (string) $this->json($login)['refreshToken'];
        $tokens[] = $first;
        $tokens[] = (string) $this->json($login)['accessToken'];
        self::assertNotNull($this->reload($user)->getLastLoginAt());

        $rotated = $this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $first]]);
        $tokens[] = (string) $this->json($rotated)['refreshToken'];
        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $first]]), 401, 'token_reused');

        $relogin = $this->json($this->api('POST', '/api/v1/auth/login', null, ['headers' => self::MOBILE, 'json' => ['email' => $email, 'password' => $password]]));
        $tokens[] = (string) $relogin['refreshToken'];
        self::assertSame(204, $this->api('POST', '/api/v1/auth/logout', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $relogin['refreshToken']]])->getStatusCode());

        $newPassword = 'another secret passphrase';
        self::assertSame(200, $this->api('POST', '/api/v1/me/password', $this->reload($user), ['json' => ['currentPassword' => $password, 'newPassword' => $newPassword]])->getStatusCode());
        self::assertLessThan(300, $this->api('POST', '/api/v1/auth/password-reset', null, ['json' => ['email' => $email]])->getStatusCode());
        self::assertSame(1, $this->runQueued('async', SendPasswordResetEmail::class));

        $types = $this->db()->fetchFirstColumn('SELECT type FROM activity_event ORDER BY id');
        foreach (['account.registered', 'auth.login_failed', 'auth.login_succeeded', 'auth.token_reused', 'auth.logout', 'auth.password_changed', 'auth.password_reset_requested'] as $type) {
            self::assertContains($type, $types);
        }
        self::assertSame(2, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'auth.login_failed'"));
        self::assertSame(
            [['subject_user_id' => $id, 'reason' => 'bad_password'], ['subject_user_id' => null, 'reason' => 'unknown_account']],
            $this->db()->fetchAllAssociative("SELECT subject_user_id, metadata->>'reason' AS reason FROM activity_event WHERE type = 'auth.login_failed' ORDER BY subject_user_id NULLS LAST"),
        );
        $success = $this->db()->fetchAssociative("SELECT actor_id, subject_user_id, ip_prefix, octet_length(ip_hash) AS hash_len, user_agent FROM activity_event WHERE type = 'auth.login_succeeded' ORDER BY id LIMIT 1");
        self::assertSame(['actor_id' => $id, 'subject_user_id' => $id, 'ip_prefix' => '127.0.0.0/24', 'hash_len' => 16, 'user_agent' => self::UA], $success);
        self::assertSame($id, $this->db()->fetchOne("SELECT subject_user_id FROM activity_event WHERE type = 'auth.token_reused'"));

        // Nothing secret anywhere in any row (every column, metadata and user agent included).
        $dump = mb_strtolower(implode("\n", $this->db()->fetchFirstColumn('SELECT row_to_json(e)::text FROM activity_event e')));
        foreach ([$email, 'journal.secret', $password, $newPassword, '@', ...$tokens] as $secret) {
            self::assertStringNotContainsString(mb_strtolower($secret), $dump);
        }
    }

    public function testContentReportsAndUploadRejectionsAreJournaled(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $post = $this->makePost($alice, $track);

        $r = $this->api('POST', '/api/v1/posts/'.$post->getId().'/comments', $bob, ['json' => ['body' => 'nice']]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
        $commentId = (string) $this->json($r)['id'];
        self::assertSame(
            ['actor_id' => $bob->getId()->toRfc4122(), 'target_type' => 'comment', 'target_id' => $commentId],
            $this->db()->fetchAssociative("SELECT actor_id, target_type, target_id FROM activity_event WHERE type = 'content.comment_created'"),
        );

        for ($i = 0; $i < 5; ++$i) {
            $reporter = $this->createUser('rep'.$i);
            self::assertSame(201, $this->api('POST', '/api/v1/reports', $reporter, ['json' => ['targetType' => 'comment', 'targetId' => $commentId, 'reason' => 'spam']])->getStatusCode());
        }
        self::assertSame(5, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'report.created' AND subject_user_id = ? AND metadata->>'reason' = 'spam'", [$bob->getId()->toRfc4122()]));
        self::assertSame(
            [['subject_user_id' => $bob->getId()->toRfc4122(), 'target_id' => $commentId, 'n' => '5']],
            $this->db()->fetchAllAssociative("SELECT subject_user_id, target_id, metadata->>'reportCount' AS n FROM activity_event WHERE type = 'report.auto_hidden'"),
        );

        self::assertSame(204, $this->api('DELETE', '/api/v1/tracks/'.$track->getId(), $alice)->getStatusCode());
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'content.track_deleted' AND target_id = ? AND subject_user_id = ?", [$track->getId()->toRfc4122(), $alice->getId()->toRfc4122()]));

        $processing = $this->makePost($alice, $this->createTrack($this->createArtist($alice, 'Other')), false);
        $failure = new PostMediaFailure($this->em(), static::getContainer()->get(ActivityJournal::class));
        $failure->mark($processing->getId(), FailureReason::TooLarge);
        $failure->mark($processing->getId(), FailureReason::TooLarge);
        self::assertSame(
            [['subject_user_id' => $alice->getId()->toRfc4122(), 'target_type' => 'post', 'reason' => 'too_large']],
            $this->db()->fetchAllAssociative("SELECT subject_user_id, target_type, metadata->>'reason' AS reason FROM activity_event WHERE type = 'upload.rejected'"),
            'once: the second mark finds the post already failed',
        );
    }

    public function testRateLimitedIsJournaledAtMostOncePerMinuteAndBucket(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $bob = $this->createUser('bob');
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.report_user');
        $factory->create($bob->getId()->toRfc4122())->consume(20);
        for ($i = 0; $i < 3; ++$i) {
            $this->assertProblem($this->api('POST', '/api/v1/reports', $bob, ['json' => ['targetType' => 'track', 'targetId' => (string) $track->getId(), 'reason' => 'spam']]), 429, 'rate_limited');
        }
        self::assertSame(
            [['subject_user_id' => $bob->getId()->toRfc4122(), 'bucket' => 'report_user']],
            $this->db()->fetchAllAssociative("SELECT subject_user_id, metadata->>'bucket' AS bucket FROM activity_event WHERE type = 'abuse.rate_limited'"),
        );
    }

    public function testSuspendRoleGuardAndDecisionJournal(): void
    {
        $admin = $this->withRoles($this->createUser('boss'), ['ROLE_ADMIN']);
        $admin2 = $this->withRoles($this->createUser('boss2'), ['ROLE_ADMIN']);
        $mod = $this->withRoles($this->createUser('mod'), ['ROLE_MODERATOR']);
        $mod2 = $this->withRoles($this->createUser('mod2'), ['ROLE_MODERATOR']);
        $carol = $this->createUser('carol');
        $suspend = fn (User $as, string $target) => $this->api('POST', '/api/v1/admin/report-targets/user/'.$target.'/decision', $as, ['json' => ['action' => 'suspend', 'note' => 'abusive messages']]);

        $this->assertProblem($suspend($mod, '0190a5c4-0000-7000-8000-000000000000'), 404, 'not_found');
        $this->assertProblem($suspend($mod, (string) $mod2->getId()), 403, 'forbidden');
        $this->assertProblem($suspend($mod, (string) $admin->getId()), 403, 'forbidden');
        $this->assertProblem($suspend($admin, (string) $admin2->getId()), 403, 'forbidden');
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE category = 'admin'"));
        self::assertNull($this->db()->fetchOne('SELECT suspended_at FROM app_user WHERE id = ?', [$mod2->getId()->toRfc4122()]));

        self::assertSame(200, $suspend($admin, (string) $mod2->getId())->getStatusCode());
        self::assertSame(200, $suspend($mod, (string) $carol->getId())->getStatusCode());
        self::assertSame(
            [
                ['actor_id' => $admin->getId()->toRfc4122(), 'subject_user_id' => $mod2->getId()->toRfc4122(), 'target_type' => 'user', 'action' => 'suspend', 'n' => '0', 'note' => 'abusive messages'],
                ['actor_id' => $mod->getId()->toRfc4122(), 'subject_user_id' => $carol->getId()->toRfc4122(), 'target_type' => 'user', 'action' => 'suspend', 'n' => '0', 'note' => 'abusive messages'],
            ],
            $this->db()->fetchAllAssociative("SELECT actor_id, subject_user_id, target_type, metadata->>'action' AS action, metadata->>'reportCount' AS n, metadata->>'note' AS note FROM activity_event WHERE type = 'admin.report_decided' ORDER BY id"),
        );
    }

    public function testAdminConsoleCommands(): void
    {
        $alice = $this->withRoles($this->createUser('alice'), ['ROLE_MODERATOR']);
        $this->createUser('bob');
        $grant = $this->command('app:admin:grant');
        $revoke = $this->command('app:admin:revoke');

        self::assertSame(1, $grant->execute(['email' => 'ghost@example.test']));
        self::assertSame('User not found.', trim($grant->getDisplay()));
        self::assertSame(1, $revoke->execute(['email' => 'ghost@example.test']));
        self::assertSame('User not found.', trim($revoke->getDisplay()));

        self::assertSame(0, $grant->execute(['email' => ' Alice@Example.test ']));
        self::assertSame(['ROLE_MODERATOR', 'ROLE_ADMIN'], $this->roles('alice'));
        self::assertSame(0, $grant->execute(['email' => 'alice@example.test']), 'already admin: no-op');
        self::assertSame(1, $revoke->execute(['email' => 'alice@example.test']));
        self::assertStringContainsString('last admin', $revoke->getDisplay());
        self::assertSame(0, $revoke->execute(['email' => 'bob@example.test']), 'not an admin: no-op');

        self::assertSame(0, $grant->execute(['email' => 'bob@example.test']));
        self::assertSame(0, $revoke->execute(['email' => 'alice@example.test']));
        self::assertSame(['ROLE_MODERATOR'], $this->roles('alice'), 'other roles kept');
        self::assertSame(['ROLE_ADMIN'], $this->roles('bob'));

        self::assertSame(
            [
                ['type' => 'admin.admin_granted', 'actor_id' => null, 'username' => 'alice', 'from' => 'ROLE_MODERATOR', 'to' => 'ROLE_ADMIN'],
                ['type' => 'admin.admin_granted', 'actor_id' => null, 'username' => 'bob', 'from' => 'ROLE_USER', 'to' => 'ROLE_ADMIN'],
                ['type' => 'admin.admin_revoked', 'actor_id' => null, 'username' => 'alice', 'from' => 'ROLE_ADMIN', 'to' => 'ROLE_MODERATOR'],
            ],
            $this->db()->fetchAllAssociative("SELECT e.type, e.actor_id, u.username, e.metadata->>'from' AS from, e.metadata->>'to' AS to FROM activity_event e JOIN app_user u ON u.id = e.subject_user_id ORDER BY e.id"),
        );
        self::assertSame('alice', $this->json($this->api('GET', '/api/v1/me', $this->reload($alice)))['username'] ?? null);
    }

    /** W-77 F27: no grant to a suspended or unverified account; suspended admins do not count. */
    public function testAdminGrantRefusesSuspendedOrUnverifiedAndLastAdminIgnoresSuspended(): void
    {
        $this->createUser('carl');
        $this->createUser('dana', verified: false);
        $this->createUser('erin');
        $this->createUser('fred');
        $this->db()->executeStatement("UPDATE app_user SET suspended_at = now() WHERE username IN ('carl', 'fred')");
        $grant = $this->command('app:admin:grant');
        $revoke = $this->command('app:admin:revoke');

        self::assertSame(1, $grant->execute(['email' => 'carl@example.test']));
        self::assertStringContainsString('suspended', $grant->getDisplay());
        self::assertSame(1, $grant->execute(['email' => 'dana@example.test']));
        self::assertStringContainsString('verified', $grant->getDisplay());
        self::assertSame(0, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'admin.admin_granted'"));

        // fred is an admin who got suspended later: erin is the only active admin.
        $this->db()->executeStatement("UPDATE app_user SET roles = '[\"ROLE_ADMIN\"]' WHERE username = 'fred'");
        self::assertSame(0, $grant->execute(['email' => 'erin@example.test']));
        self::assertSame(1, $revoke->execute(['email' => 'erin@example.test']));
        self::assertStringContainsString('last admin', $revoke->getDisplay());
        self::assertSame(0, $revoke->execute(['email' => 'fred@example.test']), 'a suspended admin can always be revoked');
        self::assertSame(['ROLE_ADMIN'], $this->roles('erin'));
    }

    public function testPurgeRetention(): void
    {
        $alice = $this->createUser('alice');
        $now = new \DateTimeImmutable();
        $event = function (string $type, string $age) use ($alice): string {
            $id = Uuid::v7()->toRfc4122();
            $this->db()->executeStatement(
                'INSERT INTO activity_event (id, category, type, subject_user_id, created_at) VALUES (?, ?, ?, ?, ?)',
                [$id, explode('.', $type)[0], $type, $alice->getId()->toRfc4122(), new \DateTimeImmutable($age)->format('Y-m-d H:i:sP')],
            );

            return $id;
        };
        $keep = [$event('auth.logout', '-89 days'), $event('admin.user_suspended', '-364 days'), $event('admin.admin_granted', '-100 days')];
        $event('auth.logout', '-91 days');
        $event('report.created', '-200 days');
        $event('admin.user_suspended', '-366 days');
        $alert = function (string $status, ?string $ackAge) use ($alice, $now): string {
            $id = Uuid::v7()->toRfc4122();
            $this->db()->executeStatement(
                "INSERT INTO security_alert (id, rule, severity, subject_user_id, first_seen_at, last_seen_at, status, acknowledged_at, created_at) VALUES (?, 'token_reuse', 'high', ?, ?, ?, ?, ?, ?)",
                [$id, $alice->getId()->toRfc4122(), '2020-01-01', '2020-01-01', $status, null === $ackAge ? null : new \DateTimeImmutable($ackAge)->format('Y-m-d H:i:sP'), $now->format('Y-m-d H:i:sP')],
            );

            return $id;
        };
        $keepAlerts = [$alert('open', null), $alert('acknowledged', '-179 days')];
        $alert('acknowledged', '-181 days');

        // As the worker does (the message is routed to `async`).
        static::getContainer()->get('messenger.default_bus')->dispatch(new PurgeActivityEvents(), [new \Symfony\Component\Messenger\Stamp\ReceivedStamp('async')]);

        $left = $this->db()->fetchFirstColumn('SELECT id FROM activity_event ORDER BY id');
        sort($keep);
        self::assertSame($keep, $left);
        $leftAlerts = $this->db()->fetchFirstColumn('SELECT id FROM security_alert ORDER BY id');
        sort($keepAlerts);
        self::assertSame($keepAlerts, $leftAlerts);
    }

    public function testSecurityAlertSubjectKeyAndOpenUniqueness(): void
    {
        $alice = $this->createUser('alice');
        $insert = fn (string $status): int|string => $this->db()->executeStatement(
            "INSERT INTO security_alert (id, rule, severity, subject_user_id, first_seen_at, last_seen_at, status, created_at) VALUES (?, 'login_failures', 'medium', ?, now(), now(), ?, now())",
            [Uuid::v7()->toRfc4122(), $alice->getId()->toRfc4122(), $status],
        );
        $insert('open');
        $insert('acknowledged');
        $insert('acknowledged');
        self::assertSame('login_failures:'.$alice->getId()->toRfc4122(), $this->db()->fetchOne("SELECT subject_key FROM security_alert WHERE status = 'open'"));

        $this->db()->executeStatement('SAVEPOINT dup');
        try {
            $insert('open');
            self::fail('a second open alert for the same subject must be refused');
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
            $this->db()->executeStatement('ROLLBACK TO SAVEPOINT dup');
        }
        $this->db()->executeStatement('SAVEPOINT bad');
        try {
            $this->db()->executeStatement("INSERT INTO activity_event (id, category, type, created_at) VALUES (?, 'auth', 'admin.admin_granted', now())", [Uuid::v7()->toRfc4122()]);
            self::fail('type and category must agree');
        } catch (\Doctrine\DBAL\Exception\DriverException) {
            $this->db()->executeStatement('ROLLBACK TO SAVEPOINT bad');
        }
        self::assertSame(ActivityType::AdminGranted->category()->value, 'admin');
    }

    /** @param list<string> $roles */
    private function withRoles(User $user, array $roles): User
    {
        $user = $this->managed($user);
        $user->setRoles($roles);
        $this->em()->flush();

        return $user;
    }

    /** @return list<string> */
    private function roles(string $username): array
    {
        return json_decode((string) $this->db()->fetchOne('SELECT roles FROM app_user WHERE username = ?', [$username]), true, 4, \JSON_THROW_ON_ERROR);
    }

    private function command(string $name): CommandTester
    {
        $kernel = static::$kernel;
        self::assertNotNull($kernel);

        return new CommandTester(new Application($kernel)->find($name));
    }

    private function makePost(User $author, Track $track, bool $ready = true): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        if ($ready) {
            self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        }
        $this->em()->clear();

        return $post;
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }
}
