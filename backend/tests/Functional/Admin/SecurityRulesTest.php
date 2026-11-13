<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Activity\ActivityJournal;
use App\Activity\SecurityRules;
use App\Enum\ActivityType;
use App\Message\EvaluateSecurityRules;
use App\MessageHandler\EvaluateSecurityRulesHandler;
use App\Tests\Functional\WaveApiTestCase;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Uid\Uuid;

/**
 * W-72: the 8 rules of DATA_MODEL §16. For each: threshold − 1 → no alert, threshold → one open
 * alert (hit 1), more hits → hit_count grows on the same open row; once acknowledged, the next
 * hit opens a new row.
 */
final class SecurityRulesTest extends WaveApiTestCase
{
    private const string IP = "\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10";

    /** @return iterable<string, array{string, string, int, string}> type, rule, threshold, keying */
    public static function rules(): iterable
    {
        yield 'login_failures by account' => ['auth.login_failed', 'login_failures', SecurityRules::LOGIN_FAILURES_PER_ACCOUNT, 'subject'];
        yield 'login_failures by ip' => ['auth.login_failed', 'login_failures', SecurityRules::LOGIN_FAILURES_PER_IP, 'ip'];
        yield 'token_reuse' => ['auth.token_reused', 'token_reuse', 1, 'subject'];
        yield 'mass_registration' => ['account.registered', 'mass_registration', SecurityRules::MASS_REGISTRATION, 'ip'];
        yield 'report_burst' => ['report.auto_hidden', 'report_burst', 1, 'target'];
        yield 'report_spree' => ['report.created', 'report_spree', SecurityRules::REPORT_SPREE, 'actor'];
        yield 'upload_abuse' => ['upload.rejected', 'upload_abuse', SecurityRules::UPLOAD_ABUSE, 'subject'];
        yield 'rate_limit_hammering by user' => ['abuse.rate_limited', 'rate_limit_hammering', SecurityRules::RATE_LIMIT_HAMMERING, 'subject'];
        yield 'rate_limit_hammering anonymous' => ['abuse.rate_limited', 'rate_limit_hammering', SecurityRules::RATE_LIMIT_HAMMERING, 'ip'];
        yield 'privilege_granted' => ['admin.admin_granted', 'privilege_granted', 1, 'subject'];
    }

    #[DataProvider('rules')]
    public function testThresholdThenHitCountOnOneOpenRow(string $type, string $rule, int $threshold, string $keying): void
    {
        $user = $this->createUser('alice')->getId()->toRfc4122();
        $other = $this->createUser('bob')->getId()->toRfc4122();
        $target = Uuid::v7()->toRfc4122();
        $base = new \DateTimeImmutable('-30 minutes');
        $fire = function (int $i) use ($type, $keying, $user, $other, $target, $base): void {
            $this->evaluate($this->event($type, $base->modify('+'.$i.' seconds'), match ($keying) {
                'subject' => ['subject_user_id' => $user, 'ip_hash' => self::IP],
                'ip' => ['ip_hash' => self::IP],
                'actor' => ['actor_id' => $user, 'subject_user_id' => $other],
                default => ['actor_id' => $user, 'subject_user_id' => $other, 'target_type' => 'track', 'target_id' => $target],
            }));
        };

        for ($i = 1; $i < $threshold; ++$i) {
            $fire($i);
        }
        self::assertSame([], $this->alerts($rule), 'threshold - 1');
        $fire($threshold);
        $alerts = $this->alerts($rule);
        self::assertCount(1, $alerts);
        self::assertSame(1, $alerts[0]['hit_count']);
        self::assertSame(SecurityRules::severity(\App\Enum\AlertRule::from($rule))->value, $alerts[0]['severity']);
        match ($keying) {
            'subject', 'actor' => self::assertSame([$user, null, null], [$alerts[0]['subject_user_id'], $alerts[0]['subject_ip_hash'], $alerts[0]['subject_target_id']]),
            'ip' => self::assertSame([null, bin2hex(self::IP)], [$alerts[0]['subject_user_id'], $alerts[0]['subject_ip_hash']]),
            default => self::assertSame([null, 'track', $target], [$alerts[0]['subject_user_id'], $alerts[0]['subject_target_type'], $alerts[0]['subject_target_id']]),
        };
        $fire($threshold + 1);
        $fire($threshold + 2);
        $alerts = $this->alerts($rule);
        self::assertCount(1, $alerts, 'no second open row');
        self::assertSame(3, $alerts[0]['hit_count']);

        $this->em()->getConnection()->executeStatement("UPDATE security_alert SET status = 'acknowledged'");
        $fire($threshold + 3);
        self::assertCount(1, $this->alerts($rule), 'a new hit after acknowledgement opens a fresh row');
        self::assertSame(2, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM security_alert'));
    }

    public function testWindowEndsAtTheEventAndOldEventsDoNotCount(): void
    {
        $user = $this->createUser('alice')->getId()->toRfc4122();
        $old = new \DateTimeImmutable('-2 hours');
        for ($i = 0; $i < SecurityRules::UPLOAD_ABUSE - 1; ++$i) {
            $this->event('upload.rejected', $old->modify('+'.$i.' seconds'), ['subject_user_id' => $user]);
        }
        $this->evaluate($this->event('upload.rejected', new \DateTimeImmutable(), ['subject_user_id' => $user]));
        self::assertSame([], $this->alerts('upload_abuse'), '4 events 2 h ago + 1 now');

        // A late worker evaluating an old event still counts in that event's window.
        $last = $this->event('upload.rejected', $old->modify('+10 seconds'), ['subject_user_id' => $user]);
        $this->evaluate($last);
        self::assertCount(1, $this->alerts('upload_abuse'));
    }

    public function testRateLimitedByAnonymousIpIgnoresSignedInEventsFromThatIp(): void
    {
        $user = $this->createUser('alice')->getId()->toRfc4122();
        $at = new \DateTimeImmutable('-1 minute');
        for ($i = 0; $i < SecurityRules::RATE_LIMIT_HAMMERING - 1; ++$i) {
            $this->event('abuse.rate_limited', $at->modify('+'.$i.' seconds'), ['subject_user_id' => $user, 'ip_hash' => self::IP]);
        }
        $this->evaluate($this->event('abuse.rate_limited', $at->modify('+30 seconds'), ['ip_hash' => self::IP]));
        self::assertSame([], $this->alerts('rate_limit_hammering'));
    }

    public function testJournalDispatchesEvaluationForWatchedTypesOnly(): void
    {
        $user = $this->createUser('alice');
        $journal = static::getContainer()->get(ActivityJournal::class);
        $journal->record(ActivityType::AdminGranted, null, $user); // admin: inserted + dispatched at once
        $journal->record(ActivityType::JournalViewed, null, $user);
        $journal->record(ActivityType::LoginSucceeded, $user, $user); // non-admin, pending under the test transaction
        $queued = $this->queued('async', EvaluateSecurityRules::class);
        self::assertCount(1, $queued);
        $type = $this->em()->getConnection()->fetchOne('SELECT type FROM activity_event WHERE id = ?', [$queued[0]->eventId]);
        self::assertSame('admin.admin_granted', $type);

        self::assertSame(1, $this->runQueued('async', EvaluateSecurityRules::class));
        self::assertCount(1, $this->alerts('privilege_granted'));
        foreach (ActivityType::cases() as $t) {
            self::assertSame(null !== SecurityRules::ruleFor($t), SecurityRules::watches($t));
        }
    }

    public function testUnknownOrPurgedEventIsSkipped(): void
    {
        $this->evaluate(Uuid::v7()->toRfc4122());
        $this->evaluate('not-a-uuid');
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM security_alert'));
    }

    private function evaluate(string $eventId): void
    {
        static::getContainer()->get(EvaluateSecurityRulesHandler::class)(new EvaluateSecurityRules($eventId));
    }

    /** @param array<string, string> $columns */
    private function event(string $type, \DateTimeImmutable $at, array $columns): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->insert('activity_event', [
            'id' => $id, 'category' => explode('.', $type)[0], 'type' => $type,
            'metadata' => '{}', 'created_at' => $at->format('Y-m-d H:i:s.uP'),
        ] + $columns, ['ip_hash' => ParameterType::BINARY]);

        return $id;
    }

    /** @return list<array{hit_count: int, severity: string, subject_user_id: ?string, subject_ip_hash: ?string, subject_target_type: ?string, subject_target_id: ?string}> */
    private function alerts(string $rule): array
    {
        /** @var list<array{hit_count: int, severity: string, subject_user_id: ?string, subject_ip_hash: ?string, subject_target_type: ?string, subject_target_id: ?string}> $rows */
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT hit_count, severity, subject_user_id, encode(subject_ip_hash, 'hex') AS subject_ip_hash, subject_target_type, subject_target_id FROM security_alert WHERE rule = ? AND status = 'open'",
            [$rule],
        );

        return $rows;
    }
}
