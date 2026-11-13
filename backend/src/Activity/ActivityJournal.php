<?php

declare(strict_types=1);

namespace App\Activity;

use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Message\EvaluateSecurityRules;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §15 write path (ADR 013).
 *
 * - `admin.*` events are inserted at once, in the caller's transaction: no admin action without
 *   its row, and a failed insert fails the action.
 * - Every other event must never fail the request: inserted at once when no transaction is open,
 *   otherwise kept in memory and written by {@see flush()} (kernel/console terminate, end of a
 *   worker message, ActivityJournalFlusher), outside the business transaction. A failure is
 *   logged and swallowed. Callers record those events AFTER their transaction committed.
 *
 * Privacy: IP → prefix + keyed hash, user agent cleaned and truncated, metadata reduced to the
 * whitelist below (ids, enum values, counts; `note` only on admin events). Events recorded
 * outside an HTTP request (console, workers) carry no IP and no user agent.
 */
final class ActivityJournal
{
    public const int NOTE_MAX = 500;

    /** Metadata keys allowed in a row; anything else is dropped. Never emails, tokens, passwords, user text. */
    public const array METADATA_KEYS = ['action', 'reportCount', 'from', 'to', 'reason', 'bucket', 'note', 'contentType', 'count', 'rule', 'level'];

    /** Values allowed for every key but `note`: identifiers, enum values, role names, uuids. */
    private const string TOKEN_VALUE = '/^[A-Za-z0-9_.:\-]{1,64}$/';

    /** @var list<array<string, mixed>> non-admin rows waiting for the end of the transaction */
    private array $pending = [];

    public function __construct(
        private readonly Connection $db,
        private readonly RequestStack $requests,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'default::ACTIVITY_IP_PEPPER')]
        private readonly ?string $ipPepper = null,
        private readonly ?MessageBusInterface $bus = null,
    ) {
    }

    /**
     * @param User|Uuid|null                               $actor      who acted; null = anonymous or console
     * @param User|Uuid|null                               $subject    the user the event is about
     * @param ReportTargetType|'alert'|'announcement'|null $targetType
     * @param array<string, mixed>                         $metadata   filtered by {@see METADATA_KEYS}
     */
    public function record(
        ActivityType $type,
        User|Uuid|null $actor = null,
        User|Uuid|null $subject = null,
        ReportTargetType|string|null $targetType = null,
        ?Uuid $targetId = null,
        array $metadata = [],
    ): void {
        $row = $this->row($type, $actor, $subject, $targetType, $targetId, $metadata);
        if ($type->isAdmin()) {
            $this->insert($row);
            // In the caller's transaction too (Doctrine transport): no alert for a rolled-back grant.
            $this->evaluate($row);

            return;
        }
        $this->pending[] = $row;
        if (!$this->db->isTransactionActive()) {
            $this->flush();
        }
    }

    /**
     * `abuse.rate_limited` (§15): at most one row per bucket and per user (or per IP hash when
     * anonymous) and per minute. Best effort under concurrency (a duplicate is harmless).
     */
    public function recordRateLimited(string $bucket, ?User $user): void
    {
        try {
            $row = $this->row(ActivityType::RateLimited, $user, $user, null, null, ['bucket' => $bucket]);
            if (null === $row['subject_user_id'] && null === $row['ip_hash']) {
                return;
            }
            $since = $this->clock->now()->sub(new \DateInterval('PT1M'))->format('Y-m-d H:i:s.uP');
            $recent = null !== $row['subject_user_id']
                ? $this->db->fetchOne(
                    "SELECT 1 FROM activity_event WHERE subject_user_id = :s AND type = 'abuse.rate_limited' AND created_at > :since AND metadata->>'bucket' = :b LIMIT 1",
                    ['s' => $row['subject_user_id'], 'since' => $since, 'b' => $bucket],
                )
                : $this->db->fetchOne(
                    "SELECT 1 FROM activity_event WHERE ip_hash = :h AND type = 'abuse.rate_limited' AND created_at > :since AND subject_user_id IS NULL AND metadata->>'bucket' = :b LIMIT 1",
                    ['h' => $row['ip_hash'], 'since' => $since, 'b' => $bucket],
                    ['h' => ParameterType::BINARY],
                );
            if (false !== $recent) {
                return;
            }
        } catch (\Throwable $e) {
            $this->logger->error('ActivityJournal: rate-limit event skipped', ['exception' => $e]);

            return;
        }
        $this->pending[] = $row;
        if (!$this->db->isTransactionActive()) {
            $this->flush();
        }
    }

    /** Writes the deferred non-admin rows; never throws. */
    public function flush(): void
    {
        if ([] === $this->pending || $this->db->isTransactionActive()) {
            return;
        }
        $rows = $this->pending;
        $this->pending = [];
        foreach ($rows as $row) {
            try {
                $this->insert($row);
            } catch (\Throwable $e) {
                $this->logger->error('ActivityJournal: event not recorded', ['type' => $row['type'], 'exception' => $e]);
                continue;
            }
            try {
                $this->evaluate($row);
            } catch (\Throwable $e) {
                $this->logger->error('ActivityJournal: security rules not dispatched', ['type' => $row['type'], 'exception' => $e]);
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function row(ActivityType $type, User|Uuid|null $actor, User|Uuid|null $subject, ReportTargetType|string|null $targetType, ?Uuid $targetId, array $metadata): array
    {
        if (\is_string($targetType) && !\in_array($targetType, ['alert', 'announcement'], true)) {
            throw new \InvalidArgumentException('Unknown journal target type.');
        }
        $request = $this->requests->getMainRequest();
        $ip = $request?->getClientIp();
        $pepper = (string) $this->ipPepper;

        return [
            'id' => Uuid::v7()->toRfc4122(),
            'category' => $type->category()->value,
            'type' => $type->value,
            'actor_id' => self::userId($actor),
            'subject_user_id' => self::userId($subject),
            'target_type' => $targetType instanceof ReportTargetType ? $targetType->value : $targetType,
            'target_id' => $targetId?->toRfc4122(),
            'ip_prefix' => null === $ip ? null : ClientFingerprint::ipPrefix($ip),
            'ip_hash' => null === $ip || '' === $pepper ? null : ClientFingerprint::ipHash($ip, $pepper),
            'user_agent' => ClientFingerprint::userAgent($request?->headers->get('User-Agent')),
            'metadata' => json_encode((object) self::metadata($metadata, $type->isAdmin()), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s.uP'),
        ];
    }

    /**
     * Watched types (DATA_MODEL §16) dispatch the async detector once their row is written.
     *
     * @param array<string, mixed> $row
     */
    private function evaluate(array $row): void
    {
        $type = ActivityType::tryFrom((string) $row['type']);
        if (null !== $this->bus && null !== $type && SecurityRules::watches($type)) {
            $this->bus->dispatch(new EvaluateSecurityRules((string) $row['id']));
        }
    }

    /** @param array<string, mixed> $row */
    private function insert(array $row): void
    {
        $this->db->executeStatement(
            'INSERT INTO activity_event (id, category, type, actor_id, subject_user_id, target_type, target_id, ip_prefix, ip_hash, user_agent, metadata, created_at)
             VALUES (:id, :category, :type, :actor_id, :subject_user_id, :target_type, :target_id, :ip_prefix, :ip_hash, :user_agent, CAST(:metadata AS jsonb), :created_at)',
            $row,
            ['ip_hash' => ParameterType::BINARY],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, int|bool|string>
     */
    public static function metadata(array $metadata, bool $admin): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!\in_array($key, self::METADATA_KEYS, true)) {
                continue;
            }
            if ('note' === $key) {
                if ($admin && \is_string($value)) {
                    $note = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', mb_scrub($value, 'UTF-8')));
                    if ('' !== $note) {
                        $clean[$key] = mb_substr($note, 0, self::NOTE_MAX);
                    }
                }
                continue;
            }
            if ($value instanceof \BackedEnum) {
                $value = $value->value;
            }
            if (\is_int($value) || \is_bool($value) || (\is_string($value) && 1 === preg_match(self::TOKEN_VALUE, $value))) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    private static function userId(User|Uuid|null $user): ?string
    {
        return match (true) {
            $user instanceof User => $user->getId()->toRfc4122(),
            $user instanceof Uuid => $user->toRfc4122(),
            default => null,
        };
    }
}
