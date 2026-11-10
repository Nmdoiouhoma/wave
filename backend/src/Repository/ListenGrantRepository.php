<?php

declare(strict_types=1);

namespace App\Repository;

use App\Listen\ListenRules;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * `listen_grant` (DATA_MODEL §11, ADR 010, W-26 F13): written by stream-url, consumed by
 * every listen report, purged daily. Plain SQL in the caller's transaction.
 */
final readonly class ListenGrantRepository
{
    public function __construct(private Connection $db)
    {
    }

    /** Upsert: a re-fetch within the session keeps `issued_at`; a stale grant (> reference + 30 min) is renewed. */
    public function grant(Uuid $userId, Uuid $trackId, int $referenceMs): void
    {
        $this->db->executeStatement(
            'INSERT INTO listen_grant (user_id, track_id, issued_at) VALUES (:u, :t, clock_timestamp())
             ON CONFLICT (user_id, track_id) DO UPDATE SET issued_at = clock_timestamp()
             WHERE listen_grant.issued_at < clock_timestamp() - make_interval(secs => :stale)',
            ['u' => $userId->toRfc4122(), 't' => $trackId->toRfc4122(), 'stale' => ($referenceMs + ListenRules::GRANT_SLACK_MS) / 1000],
        );
    }

    /**
     * Same upsert for every clip of one response (DATA_MODEL §9: one multi-row upsert per
     * response). Pairs are deduplicated per track (the longest reference wins), since one
     * INSERT … ON CONFLICT cannot touch a row twice.
     *
     * @param array<string, int> $referenceMsByTrackId track id (RFC 4122) => reference in ms
     */
    public function grantMany(Uuid $userId, array $referenceMsByTrackId): void
    {
        if ([] === $referenceMsByTrackId) {
            return;
        }
        $values = [];
        $params = ['u' => $userId->toRfc4122()];
        $i = 0;
        foreach ($referenceMsByTrackId as $trackId => $referenceMs) {
            $values[] = "(CAST(:t{$i} AS uuid), CAST(:s{$i} AS double precision))";
            $params["t{$i}"] = (string) $trackId;
            $params["s{$i}"] = ($referenceMs + ListenRules::GRANT_SLACK_MS) / 1000;
            ++$i;
        }
        $this->db->executeStatement(
            'WITH refs (track_id, stale) AS (VALUES '.implode(', ', $values).')
             INSERT INTO listen_grant (user_id, track_id, issued_at)
             SELECT :u, refs.track_id, clock_timestamp() FROM refs
             ON CONFLICT (user_id, track_id) DO UPDATE SET issued_at = clock_timestamp()
             WHERE listen_grant.issued_at < clock_timestamp()
                 - make_interval(secs => (SELECT r.stale FROM refs r WHERE r.track_id = listen_grant.track_id))',
            $params,
        );
    }

    /**
     * Consumes the grant (`DELETE … RETURNING`, which also serialises concurrent reports of the pair).
     *
     * @return int|null age of the grant in ms, null when there was none
     */
    public function consume(Uuid $userId, Uuid $trackId): ?int
    {
        $age = $this->db->fetchOne(
            'DELETE FROM listen_grant WHERE user_id = :u AND track_id = :t
             RETURNING floor(extract(epoch FROM clock_timestamp() - issued_at) * 1000)::bigint',
            ['u' => $userId->toRfc4122(), 't' => $trackId->toRfc4122()],
        );

        return false === $age ? null : (int) $age;
    }

    /** @return int rows deleted */
    public function purgeOlderThan(\DateTimeImmutable $before): int
    {
        return (int) $this->db->executeStatement('DELETE FROM listen_grant WHERE issued_at < :b', ['b' => $before->format('Y-m-d H:i:s.uP')]);
    }
}
