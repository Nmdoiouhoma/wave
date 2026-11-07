<?php

declare(strict_types=1);

namespace App\Listen;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\ListenSource;
use App\Recommendation\AffinityTrigger;
use App\Repository\ListenGrantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Stores counted listens only (DATA_MODEL §11): row + `track.play_count + 1` + affinity
 * recompute request, in one transaction with the consumption of the listen grant (ADR 010);
 * serialised per (user, track) so the anti-replay window and the daily cap hold under concurrent reports.
 */
final readonly class ListenRecorder
{
    public function __construct(private EntityManagerInterface $em, private ListenGrantRepository $grants, private AffinityTrigger $affinity)
    {
    }

    /** @return array{bool, int} [counted, play_count] */
    public function record(User $user, Track $track, ListenSource $source, int $listenedMs, int $referenceMs): array
    {
        return $this->em->wrapInTransaction(function () use ($user, $track, $source, $listenedMs, $referenceMs): array {
            $db = $this->em->getConnection();
            $u = $user->getId()->toRfc4122();
            $t = $track->getId()->toRfc4122();
            $playCount = static fn (): int => (int) $db->fetchOne('SELECT play_count FROM track WHERE id = :t', ['t' => $t]);

            // ADR 010: every report consumes the grant, counted or not.
            $grantAgeMs = $this->grants->consume($user->getId(), $track->getId());
            if (null === $grantAgeMs
                || !ListenRules::grantFresh($grantAgeMs, $referenceMs)
                || !ListenRules::longEnough($listenedMs, $referenceMs)
                || !ListenRules::elapsedOk($listenedMs, $grantAgeMs)) {
                return [false, $playCount()];
            }

            $db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:k))', ['k' => 'listen:'.$u.':'.$t]);
            $recent = $db->fetchOne(
                'SELECT 1 FROM listen WHERE user_id = :u AND track_id = :t AND created_at > clock_timestamp() - make_interval(secs => :w) LIMIT 1',
                ['u' => $u, 't' => $t, 'w' => ListenRules::windowMs($referenceMs) / 1000],
            );
            $today = (int) $db->fetchOne(
                "SELECT count(*) FROM (SELECT 1 FROM listen WHERE user_id = :u AND track_id = :t AND created_at > clock_timestamp() - interval '24 hours' LIMIT :cap) l",
                ['u' => $u, 't' => $t, 'cap' => ListenRules::DAILY_CAP],
            );
            if (false !== $recent || $today >= ListenRules::DAILY_CAP) {
                return [false, $playCount()];
            }
            $db->executeStatement(
                'INSERT INTO listen (id, user_id, track_id, post_id, source, listened_ms, created_at) VALUES (:id, :u, :t, NULL, :s, :ms, clock_timestamp())',
                ['id' => Uuid::v7()->toRfc4122(), 'u' => $u, 't' => $t, 's' => $source->value, 'ms' => $listenedMs],
            );
            $count = (int) $db->fetchOne('UPDATE track SET play_count = play_count + 1 WHERE id = :t RETURNING play_count', ['t' => $t]);
            $this->affinity->touch($user);

            return [true, $count];
        });
    }
}
