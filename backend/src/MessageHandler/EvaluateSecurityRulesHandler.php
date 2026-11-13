<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Activity\SecurityRules;
use App\Enum\ActivityType;
use App\Enum\AlertRule;
use App\Message\EvaluateSecurityRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §16 detector. Reads the journaled event, counts the matching events in the rule's
 * window (ending at that event, so a late worker gives the same answer), and when the threshold
 * is reached raises the alert with one atomic upsert on the partial unique `subject_key` index:
 * a new row, or `hit_count + 1` on the open one. Two workers evaluating two events of the same
 * subject at once end with one open row and hit_count 2 (ON CONFLICT serialises them).
 *
 * An event purged or deleted with its account meanwhile is skipped.
 */
#[AsMessageHandler]
final readonly class EvaluateSecurityRulesHandler
{
    private const string TS = 'Y-m-d H:i:s.uP';

    public function __construct(private Connection $db, private ClockInterface $clock)
    {
    }

    public function __invoke(EvaluateSecurityRules $message): void
    {
        if (!Uuid::isValid($message->eventId)) {
            return;
        }
        /** @var array{type: string, actor_id: ?string, subject_user_id: ?string, target_type: ?string, target_id: ?string, ip_hash: mixed, created_at: string}|false $event */
        $event = $this->db->fetchAssociative(
            'SELECT type, actor_id, subject_user_id, target_type, target_id, ip_hash, created_at FROM activity_event WHERE id = ?',
            [$message->eventId],
        );
        if (false === $event) {
            return;
        }
        $type = ActivityType::tryFrom($event['type']);
        $rule = null === $type ? null : SecurityRules::ruleFor($type);
        if (null === $type || null === $rule) {
            return;
        }
        $at = new \DateTimeImmutable($event['created_at']);
        $user = $event['subject_user_id'];
        $ip = \is_resource($event['ip_hash']) ? (string) stream_get_contents($event['ip_hash']) : $event['ip_hash'];
        $ip = \is_string($ip) && '' !== $ip ? $ip : null;

        switch ($rule) {
            case AlertRule::LoginFailures:
                if (null !== $user && $this->countBySubject($type, $user, $at, SecurityRules::LOGIN_FAILURES_WINDOW) >= SecurityRules::LOGIN_FAILURES_PER_ACCOUNT) {
                    $this->raise($rule, $at, user: $user);
                }
                if (null !== $ip && $this->countByIp($type, $ip, $at, SecurityRules::LOGIN_FAILURES_WINDOW, false) >= SecurityRules::LOGIN_FAILURES_PER_IP) {
                    $this->raise($rule, $at, ip: $ip);
                }
                break;
            case AlertRule::TokenReuse:
            case AlertRule::PrivilegeGranted:
                if (null !== $user) {
                    $this->raise($rule, $at, user: $user);
                } elseif (null !== $ip) {
                    $this->raise($rule, $at, ip: $ip);
                }
                break;
            case AlertRule::MassRegistration:
                if (null !== $ip && $this->countByIp($type, $ip, $at, SecurityRules::MASS_REGISTRATION_WINDOW, false) >= SecurityRules::MASS_REGISTRATION) {
                    $this->raise($rule, $at, ip: $ip);
                }
                break;
            case AlertRule::ReportBurst:
                if (null !== $event['target_type'] && null !== $event['target_id']) {
                    $this->raise($rule, $at, targetType: $event['target_type'], targetId: $event['target_id']);
                }
                break;
            case AlertRule::ReportSpree:
                // "by one user": the reporter is the actor (the subject is the target's owner).
                $actor = $event['actor_id'];
                if (null !== $actor && $this->countByActor($type, $actor, $at, SecurityRules::REPORT_SPREE_WINDOW) >= SecurityRules::REPORT_SPREE) {
                    $this->raise($rule, $at, user: $actor);
                }
                break;
            case AlertRule::UploadAbuse:
                if (null !== $user && $this->countBySubject($type, $user, $at, SecurityRules::UPLOAD_ABUSE_WINDOW) >= SecurityRules::UPLOAD_ABUSE) {
                    $this->raise($rule, $at, user: $user);
                }
                break;
            case AlertRule::RateLimitHammering:
                // Same key as the journal's 1/min dedup: the user, or the IP hash when anonymous.
                if (null !== $user) {
                    if ($this->countBySubject($type, $user, $at, SecurityRules::RATE_LIMIT_HAMMERING_WINDOW) >= SecurityRules::RATE_LIMIT_HAMMERING) {
                        $this->raise($rule, $at, user: $user);
                    }
                } elseif (null !== $ip && $this->countByIp($type, $ip, $at, SecurityRules::RATE_LIMIT_HAMMERING_WINDOW, true) >= SecurityRules::RATE_LIMIT_HAMMERING) {
                    $this->raise($rule, $at, ip: $ip);
                }
                break;
        }
    }

    /** Index (subject_user_id, id DESC). */
    private function countBySubject(ActivityType $type, string $user, \DateTimeImmutable $at, string $window): int
    {
        return (int) $this->db->fetchOne(
            'SELECT count(*) FROM activity_event WHERE subject_user_id = :u AND type = :t AND created_at > :from AND created_at <= :to',
            ['u' => $user, 't' => $type->value] + $this->window($at, $window),
        );
    }

    /** Index (category, type, created_at): the window bounds the scan. */
    private function countByActor(ActivityType $type, string $actor, \DateTimeImmutable $at, string $window): int
    {
        return (int) $this->db->fetchOne(
            'SELECT count(*) FROM activity_event WHERE category = :c AND type = :t AND created_at > :from AND created_at <= :to AND actor_id = :a',
            ['c' => $type->category()->value, 't' => $type->value, 'a' => $actor] + $this->window($at, $window),
        );
    }

    /** Index (ip_hash, type, created_at) WHERE ip_hash IS NOT NULL. */
    private function countByIp(ActivityType $type, string $ip, \DateTimeImmutable $at, string $window, bool $anonymousOnly): int
    {
        return (int) $this->db->fetchOne(
            'SELECT count(*) FROM activity_event WHERE ip_hash = :h AND type = :t AND created_at > :from AND created_at <= :to'.($anonymousOnly ? ' AND subject_user_id IS NULL' : ''),
            ['h' => $ip, 't' => $type->value] + $this->window($at, $window),
            ['h' => ParameterType::BINARY],
        );
    }

    /** @return array{from: string, to: string} */
    private function window(\DateTimeImmutable $at, string $window): array
    {
        return ['from' => $at->sub(new \DateInterval($window))->format(self::TS), 'to' => $at->format(self::TS)];
    }

    private function raise(AlertRule $rule, \DateTimeImmutable $at, ?string $user = null, ?string $ip = null, ?string $targetType = null, ?string $targetId = null): void
    {
        $this->db->executeStatement(
            "INSERT INTO security_alert (id, rule, severity, subject_user_id, subject_ip_hash, subject_target_type, subject_target_id, hit_count, first_seen_at, last_seen_at, status, created_at)
             VALUES (:id, :rule, :severity, :user, :ip, :target_type, :target_id, 1, :seen, :seen, 'open', :now)
             ON CONFLICT (subject_key) WHERE status = 'open'
             DO UPDATE SET hit_count = security_alert.hit_count + 1, last_seen_at = GREATEST(security_alert.last_seen_at, EXCLUDED.last_seen_at)",
            [
                'id' => Uuid::v7()->toRfc4122(),
                'rule' => $rule->value,
                'severity' => SecurityRules::severity($rule)->value,
                'user' => $user,
                'ip' => $ip,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'seen' => $at->format(self::TS),
                'now' => $this->clock->now()->format(self::TS),
            ],
            ['ip' => ParameterType::BINARY],
        );
    }
}
