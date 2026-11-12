<?php

declare(strict_types=1);

namespace App\Tests\Functional\Report;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\PostRepository;
use App\Repository\ReportRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/** W-33: reports, auto-hide and the moderation API (DATA_MODEL §13). */
final class ReportTest extends WaveApiTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    public function testReceiptAndCheckOrder(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $private = $this->createTrack($this->createArtist($alice, 'Other'), 'Secret', visibility: Visibility::Private);

        $r = $this->report($bob, 'track', (string) $track->getId(), ['reason' => 'spam', 'details' => '  copied  ']);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
        $body = $this->json($r);
        self::assertSame(['id', 'status', 'createdAt'], array_keys($body));
        self::assertSame('open', $body['status']);
        self::assertSame('copied', $this->db()->fetchOne('SELECT details FROM report WHERE id = ?', [$body['id']]));

        // 1. body first, even for an invisible target.
        foreach ([
            ['targetType' => 'track', 'targetId' => (string) $private->getId(), 'reason' => 'nope'],
            ['targetType' => 'song', 'targetId' => (string) $track->getId(), 'reason' => 'spam'],
            ['targetType' => 'track', 'targetId' => 'not-a-uuid', 'reason' => 'spam'],
            ['targetType' => 'track', 'reason' => 'spam'],
            ['targetType' => 'track', 'targetId' => (string) $track->getId(), 'reason' => 'spam', 'details' => str_repeat('a', 1001)],
            ['targetType' => 'track', 'targetId' => (string) $track->getId(), 'reason' => 'spam', 'status' => 'actioned'],
        ] as $bad) {
            $this->assertProblem($this->api('POST', '/api/v1/reports', $bob, ['json' => $bad]), 422, 'validation_failed');
        }
        // 2. invisible → 404; 3. own content (hidden or private included) → 422; 4. duplicate → 409.
        $this->assertProblem($this->report($bob, 'track', (string) $private->getId()), 404, 'not_found');
        $this->assertProblem($this->report($alice, 'track', (string) $private->getId()), 422, 'cannot_report_own');
        $this->assertProblem($this->report($alice, 'user', (string) $alice->getId()), 422, 'cannot_report_own');
        $this->assertProblem($this->report($bob, 'track', (string) $track->getId()), 409, 'already_reported');
        $this->assertProblem($this->report($this->createUser('carol', verified: false), 'track', (string) $track->getId()), 403, 'email_not_verified');
        self::assertSame(401, $this->api('POST', '/api/v1/reports', null, ['json' => []])->getStatusCode());
    }

    public function testInvisibleTargetsOfEveryTypeAreByteIdenticalToAnUnknownId(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $artist = $this->createArtist($alice);
        $publicTrack = $this->createTrack($artist);
        $privateTrack = $this->createTrack($artist, 'Private', visibility: Visibility::Private);
        $hiddenTrack = $this->createTrack($artist, 'Hidden');
        $playable = $this->makePost($alice, $publicTrack);
        $processing = $this->makePost($alice, $publicTrack, ready: false);
        $onPrivate = $this->makePost($alice, $privateTrack);
        $hiddenComment = $this->comment($playable, $carol, 'hidden', 'hidden');
        $commentOfInvisiblePost = $this->comment($onPrivate, $alice, 'secret');
        $privatePlaylist = $this->createPlaylist($alice, 'Mine', public: false);
        $hiddenArtist = $this->createArtist($carol, 'Ghost');
        $suspended = $this->createUser('dave');
        $this->sql("UPDATE track SET moderation = 'hidden' WHERE id = ?", [$hiddenTrack->getId()]);
        $this->sql("UPDATE artist SET moderation = 'hidden' WHERE id = ?", [$hiddenArtist->getId()]);
        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$suspended->getId()]);

        $invisible = [
            'track' => [$privateTrack->getId(), $hiddenTrack->getId()],
            'post' => [$processing->getId(), $onPrivate->getId()],
            'comment' => [$hiddenComment, $commentOfInvisiblePost],
            'playlist' => [$privatePlaylist->getId()],
            'artist' => [$hiddenArtist->getId()],
            'user' => [$suspended->getId()],
        ];
        foreach ($invisible as $type => $ids) {
            $unknown = $this->report($bob, $type, self::UNKNOWN);
            $this->assertProblem($unknown, 404, 'not_found');
            foreach ($ids as $id) {
                $r = $this->report($bob, $type, (string) $id);
                self::assertSame($unknown->getContent(false), $r->getContent(false), $type.' '.$id);
            }
        }
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM report'));
    }

    public function testFifthDistinctReporterHidesOnceAndUsersAreNeverAutoHidden(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $track = $this->createTrack($artist);
        $post = $this->makePost($alice, $track);
        $comment = $this->comment($post, $alice, 'rude');
        self::assertSame(1, $this->counter('artist', 'track_count', (string) $artist->getId()));
        self::assertSame(1, $this->counter('post', 'comment_count', (string) $post->getId()));

        $receipts = [];
        for ($i = 0; $i < 5; ++$i) {
            $reporter = $this->createUser('rep'.$i);
            foreach (['comment' => $comment, 'track' => (string) $track->getId(), 'user' => (string) $alice->getId()] as $type => $id) {
                $r = $this->report($reporter, $type, $id);
                self::assertSame(201, $r->getStatusCode(), $type.' '.$r->getContent(false));
                $receipts[] = $this->json($r);
            }
        }
        foreach ($receipts as $receipt) {
            self::assertSame(['id', 'status', 'createdAt'], array_keys($receipt));
            self::assertSame('open', $receipt['status']);
        }
        self::assertSame('hidden', $this->counterRaw('comment', 'moderation', $comment));
        self::assertSame('hidden', $this->counterRaw('track', 'moderation', (string) $track->getId()));
        self::assertSame(0, $this->counter('post', 'comment_count', (string) $post->getId()));
        self::assertSame(0, $this->counter('artist', 'track_count', (string) $artist->getId()));
        self::assertNull($this->counterRaw('app_user', 'suspended_at', (string) $alice->getId()));
        // A 6th reporter no longer sees the hidden targets.
        $this->assertProblem($this->report($this->createUser('late'), 'comment', $comment), 404, 'not_found');
        self::assertSame(201, $this->report($this->createUser('late2'), 'user', (string) $alice->getId())->getStatusCode());
    }

    public function testReportsAreRateLimited(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $bob = $this->createUser('bob');
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.report_user');
        $factory->create($bob->getId()->toRfc4122())->consume(20);
        $this->assertProblem($this->report($bob, 'track', (string) $track->getId()), 429, 'rate_limited');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM report'));
    }

    public function testNonModeratorIsForbiddenOnEveryAdminRouteBeforeAnyLookup(): void
    {
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($this->createUser('alice')));
        $routes = [];
        foreach (['track/'.$track->getId(), 'track/'.self::UNKNOWN, 'nope/garbage'] as $target) {
            $routes[] = ['GET', '/api/v1/admin/report-targets/'.$target.'/reports', null];
            $routes[] = ['POST', '/api/v1/admin/report-targets/'.$target.'/decision', ['action' => 'hide']];
        }
        $routes[] = ['GET', '/api/v1/admin/report-targets', null];
        $routes[] = ['GET', '/api/v1/admin/report-targets?status=bogus', null];
        foreach ($routes as [$method, $uri, $json]) {
            $options = null === $json ? [] : ['json' => $json];
            $this->assertProblem($this->api($method, $uri, $bob, $options), 403, 'forbidden');
            self::assertSame(401, $this->api($method, $uri, null, $options)->getStatusCode());
        }
        self::assertSame('visible', $this->counterRaw('track', 'moderation', (string) $track->getId()));
    }

    public function testQueueOrderFiltersPreviewsAndCursor(): void
    {
        $mod = $this->moderator();
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice), 'Title');
        $post = $this->makePost($alice, $track);
        $long = str_repeat('é', 300);
        $comment = $this->comment($post, $alice, $long);
        $reporters = array_map(fn (int $i): User => $this->createUser('rep'.$i), range(0, 2));
        // comment: 3 reports, track: 2 (oldest), user: 2 (newer), a deleted track: 1.
        $this->seedReport($reporters[0], 'track', (string) $track->getId(), 'spam', '-10 minutes');
        $this->seedReport($reporters[1], 'track', (string) $track->getId(), 'hate', '-9 minutes');
        $this->seedReport($reporters[0], 'user', (string) $alice->getId(), 'spam', '-8 minutes');
        $this->seedReport($reporters[1], 'user', (string) $alice->getId(), 'spam', '-7 minutes');
        foreach ($reporters as $i => $reporter) {
            $this->seedReport($reporter, 'comment', $comment, 0 === $i ? 'harassment' : 'spam', '-'.(6 - $i).' minutes');
        }
        $this->seedReport($reporters[2], 'track', self::UNKNOWN, 'other', '-1 minute');

        $page = $this->json($this->api('GET', '/api/v1/admin/report-targets', $mod));
        self::assertSame(['comment', 'track', 'user', 'track'], array_column($page['items'], 'targetType'));
        self::assertSame([3, 2, 2, 1], array_column($page['items'], 'openReportCount'));
        self::assertNull($page['nextCursor']);
        [$c, $t, $u, $gone] = $page['items'];
        self::assertSame(['harassment' => 1, 'spam' => 2], $c['reasons']);
        self::assertSame(140, mb_strlen($c['preview']['label']));
        self::assertSame('alice', $c['preview']['owner']['username']);
        self::assertSame('visible', $c['moderation']);
        self::assertNull($c['suspended']);
        self::assertSame('Title', $t['preview']['label']);
        self::assertSame(['label' => 'alice', 'imageUrl' => null], array_intersect_key($u['preview'], ['label' => 1, 'imageUrl' => 1]));
        self::assertNull($u['moderation']);
        self::assertFalse($u['suspended']);
        self::assertSame(self::UNKNOWN, $gone['targetId']);
        self::assertNull($gone['preview']);
        self::assertNull($gone['moderation']);
        self::assertLessThan($c['lastReportedAt'], $c['firstReportedAt']);

        // Cursor walk with limit=1 gives the same order.
        $seen = [];
        $cursor = null;
        do {
            $p = $this->json($this->api('GET', '/api/v1/admin/report-targets?limit=1'.(null === $cursor ? '' : '&cursor='.$cursor), $mod));
            $seen[] = $p['items'][0]['targetType'].':'.$p['items'][0]['targetId'];
            $cursor = $p['nextCursor'];
        } while (null !== $cursor && \count($seen) < 10);
        self::assertSame(array_map(static fn (array $i): string => $i['targetType'].':'.$i['targetId'], $page['items']), $seen);

        $users = $this->json($this->api('GET', '/api/v1/admin/report-targets?targetType=user', $mod));
        self::assertSame(['user'], array_column($users['items'], 'targetType'));
        self::assertSame([], $this->json($this->api('GET', '/api/v1/admin/report-targets?status=dismissed', $mod))['items']);
        $this->assertProblem($this->api('GET', '/api/v1/admin/report-targets?status=bogus', $mod), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/admin/report-targets?targetType=song', $mod), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/admin/report-targets?cursor=garbage', $mod), 400, 'invalid_cursor');

        // Reports of one target, newest first.
        $reports = $this->json($this->api('GET', '/api/v1/admin/report-targets/comment/'.$comment.'/reports?limit=2', $mod));
        self::assertSame(['rep2', 'rep1'], array_map(static fn (array $r): string => $r['reporter']['username'], $reports['items']));
        self::assertSame(['id', 'reporter', 'targetType', 'targetId', 'reason', 'details', 'status', 'handledBy', 'handledAt', 'decisionNote', 'createdAt'], array_keys($reports['items'][0]));
        $rest = $this->json($this->api('GET', '/api/v1/admin/report-targets/comment/'.$comment.'/reports?cursor='.$reports['nextCursor'], $mod));
        self::assertSame(['rep0'], array_map(static fn (array $r): string => $r['reporter']['username'], $rest['items']));
        self::assertCount(1, $this->json($this->api('GET', '/api/v1/admin/report-targets/track/'.self::UNKNOWN.'/reports', $mod))['items']);
        $this->assertProblem($this->api('GET', '/api/v1/admin/report-targets/post/'.self::UNKNOWN.'/reports', $mod), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/admin/report-targets/song/'.self::UNKNOWN.'/reports', $mod), 404, 'not_found');
        self::assertSame([], $this->json($this->api('GET', '/api/v1/admin/report-targets/post/'.$post->getId().'/reports', $mod))['items']);
    }

    public function testDecisionsAreConditionalAndValidated(): void
    {
        $mod = $this->moderator();
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $track = $this->createTrack($artist);
        $post = $this->makePost($alice, $track);
        $comment = $this->comment($post, $alice, 'rude');
        $bob = $this->createUser('bob');
        $this->seedReport($bob, 'comment', $comment, 'spam', '-1 minute');
        $this->seedReport($bob, 'track', (string) $track->getId(), 'spam', '-1 minute');
        $commentUri = '/api/v1/admin/report-targets/comment/'.$comment.'/decision';

        $r = $this->api('POST', $commentUri, $mod, ['json' => ['action' => 'hide', 'note' => ' rude ']]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        $view = $this->json($r);
        self::assertSame('hidden', $view['moderation']);
        self::assertSame(1, $view['openReportCount']);
        self::assertSame(['spam' => 1], $view['reasons']);
        $report = $this->db()->fetchAssociative('SELECT status, handled_by_id, decision_note, handled_at FROM report WHERE target_id = ?', [$comment]);
        self::assertIsArray($report);
        self::assertSame(['actioned', (string) $mod->getId(), 'rude'], [$report['status'], $report['handled_by_id'], $report['decision_note']]);
        self::assertNotNull($report['handled_at']);
        // Hide twice: the counter moves once; dismiss twice: back once. No open report left: allowed.
        self::assertSame(200, $this->api('POST', $commentUri, $mod, ['json' => ['action' => 'hide']])->getStatusCode());
        self::assertSame(0, $this->counter('post', 'comment_count', (string) $post->getId()));
        $dismissed = $this->json($this->api('POST', $commentUri, $mod, ['json' => ['action' => 'dismiss']]));
        self::assertSame('visible', $dismissed['moderation']);
        self::assertSame(0, $dismissed['openReportCount']);
        self::assertSame([], $dismissed['reasons']);
        self::assertSame(200, $this->api('POST', $commentUri, $mod, ['json' => ['action' => 'dismiss']])->getStatusCode());
        self::assertSame(1, $this->counter('post', 'comment_count', (string) $post->getId()));
        self::assertStringContainsString('"reasons":{}', $this->api('POST', $commentUri, $mod, ['json' => ['action' => 'dismiss']])->getContent());

        $trackUri = '/api/v1/admin/report-targets/track/'.$track->getId().'/decision';
        foreach (['hide', 'hide'] as $action) {
            self::assertSame(200, $this->api('POST', $trackUri, $mod, ['json' => ['action' => $action]])->getStatusCode());
            self::assertSame(0, $this->counter('artist', 'track_count', (string) $artist->getId()));
        }
        self::assertSame('actioned', $this->db()->fetchOne('SELECT status FROM report WHERE target_id = ?', [(string) $track->getId()]));
        self::assertSame(200, $this->api('POST', '/api/v1/admin/report-targets/post/'.$post->getId().'/decision', $mod, ['json' => ['action' => 'hide']])->getStatusCode());
        self::assertSame('hidden', $this->counterRaw('post', 'moderation', (string) $post->getId()));

        $this->assertProblem($this->api('POST', $trackUri, $mod, ['json' => ['action' => 'suspend']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $trackUri, $mod, ['json' => ['action' => 'ban']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $trackUri, $mod, ['json' => ['action' => 'hide', 'note' => str_repeat('a', 501)]]), 422, 'validation_failed');
        $userUri = '/api/v1/admin/report-targets/user/'.$alice->getId().'/decision';
        $this->assertProblem($this->api('POST', $userUri, $mod, ['json' => ['action' => 'hide']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/admin/report-targets/user/'.$mod->getId().'/decision', $mod, ['json' => ['action' => 'suspend']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', '/api/v1/admin/report-targets/track/'.self::UNKNOWN.'/decision', $mod, ['json' => ['action' => 'hide']]), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/admin/report-targets/song/'.self::UNKNOWN.'/decision', $mod, ['json' => ['action' => 'hide']]), 404, 'not_found');
    }

    public function testSuspendRevokesSessionsAndTheStillValidJwt(): void
    {
        $mod = $this->moderator();
        $this->createUser('bob');
        $login = $this->api('POST', '/api/v1/auth/login', null, ['json' => ['email' => 'bob@example.test', 'password' => self::PASSWORD], 'headers' => ['X-Client' => 'mobile']]);
        self::assertSame(200, $login->getStatusCode(), $login->getContent(false));
        $jwt = $this->json($login)['accessToken'];
        $bob = $this->em()->getRepository(User::class)->findOneBy(['username' => 'bob']);
        self::assertNotNull($bob);
        $bobId = $bob->getId()->toRfc4122();
        self::assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT count(*) FROM refresh_token WHERE username = ?', [$bobId]));
        $this->seedReport($this->createUser('carol'), 'user', $bobId, 'impersonation', '-1 minute');

        $r = $this->api('POST', '/api/v1/admin/report-targets/user/'.$bobId.'/decision', $mod, ['json' => ['action' => 'suspend']]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertTrue($this->json($r)['suspended']);
        self::assertNull($this->json($r)['moderation']);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM refresh_token WHERE username = ?', [$bobId]));
        self::assertSame('actioned', $this->db()->fetchOne('SELECT status FROM report WHERE target_id = ?', [$bobId]));
        $this->assertProblem($this->api('GET', '/api/v1/me', null, ['headers' => ['Authorization' => 'Bearer '.$jwt]]), 403, 'account_suspended');
        $this->assertProblem($this->api('POST', '/api/v1/admin/report-targets/user/'.$bobId.'/decision', $mod, ['json' => ['action' => 'suspend']]), 422, 'validation_failed');
        self::assertSame(200, $this->api('POST', '/api/v1/admin/report-targets/user/'.$bobId.'/decision', $mod, ['json' => ['action' => 'dismiss']])->getStatusCode());
        self::assertNotNull($this->counterRaw('app_user', 'suspended_at', $bobId));
    }

    /**
     * `DELETE /me` (phase 5) cleanup hook: reports on the user and on everything the FK cascade
     * removes with them go; reports on other users' content stay.
     */
    public function testDeleteForUserContentLeavesNoOrphanReport(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $reporter = $this->createUser('rep');
        $artist = $this->createArtist($alice, 'A', 'a-artist');
        $track = $this->createTrack($artist);
        $bobTrack = $this->createTrack($this->createArtist($bob, 'B', 'b-artist'));
        $playlist = $this->createPlaylist($alice);
        $comment = $this->comment($this->makePost($bob, $bobTrack), $alice, 'hi');
        foreach ([['user', (string) $alice->getId()], ['track', (string) $track->getId()], ['playlist', (string) $playlist->getId()], ['artist', (string) $artist->getId()], ['comment', $comment], ['track', (string) $bobTrack->getId()], ['user', (string) $bob->getId()]] as [$type, $id]) {
            $this->seedReport($reporter, $type, $id, 'spam', '2026-01-01T10:00:00Z');
        }

        $deleted = static::getContainer()->get(ReportRepository::class)->deleteForUserContent($alice->getId());

        self::assertSame(5, $deleted);
        self::assertEqualsCanonicalizing([(string) $bobTrack->getId(), (string) $bob->getId()], $this->db()->fetchFirstColumn('SELECT target_id FROM report'));
    }

    // ------------------------------------------------------------- helpers ---

    /** @param array<string, mixed> $extra */
    private function report(User $as, string $type, string $id, array $extra = ['reason' => 'spam']): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->api('POST', '/api/v1/reports', $as, ['json' => ['targetType' => $type, 'targetId' => $id] + $extra]);
    }

    private function moderator(): User
    {
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();

        return $mod;
    }

    private function seedReport(User $reporter, string $type, string $id, string $reason, string $at): void
    {
        $this->sql(
            "INSERT INTO report (id, reporter_id, target_type, target_id, reason, status, created_at) VALUES (?, ?, ?, ?, ?, 'open', ?)",
            [Uuid::v7(), $reporter->getId(), $type, $id, $reason, new \DateTimeImmutable($at)->format('Y-m-d H:i:sP')],
        );
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

    private function comment(Post $post, User $author, string $body, string $moderation = 'visible'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->sql('INSERT INTO comment (id, post_id, author_id, body, moderation, created_at) VALUES (?, ?, ?, ?, ?, now())', [$id, $post->getId(), $author->getId(), $body, $moderation]);
        if ('visible' === $moderation) {
            $this->sql('UPDATE post SET comment_count = comment_count + 1 WHERE id = ?', [$post->getId()]);
        }

        return $id;
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params): void
    {
        $this->db()->executeStatement($sql, array_map(static fn (mixed $p): mixed => $p instanceof Uuid ? $p->toRfc4122() : $p, $params));
        $this->em()->clear();
    }

    private function counter(string $table, string $column, string $id): int
    {
        return (int) $this->counterRaw($table, $column, $id);
    }

    private function counterRaw(string $table, string $column, string $id): mixed
    {
        $value = $this->db()->fetchOne("SELECT {$column} FROM {$table} WHERE id = ?", [$id]);

        return false === $value ? null : $value;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }
}
