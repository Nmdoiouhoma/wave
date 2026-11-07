<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Entity\Track;
use App\Entity\User;
use App\Message\RecomputeAffinity;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-23, W-26 F13: listens (DATA_MODEL §11, ADR 010). The test track lasts 180 s. */
final class ListenTest extends WaveApiTestCase
{
    public function testCountingRulesAndPlayCount(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));

        self::assertSame(['counted' => false, 'playCount' => 0], $this->json($this->listen($bob, $track, 29_000)));
        self::assertSame(['counted' => false, 'playCount' => 0], $this->json($this->listen($bob, $track, 182_001)), 'longer than the track + 2 s');
        self::assertSame(['counted' => true, 'playCount' => 1], $this->json($this->listen($bob, $track, 31_000)));
        self::assertSame(['counted' => false, 'playCount' => 1], $this->json($this->listen($bob, $track, 120_000)), 'second within max(30 s, 180 s)');
        self::assertSame(['counted' => true, 'playCount' => 2], $this->json($this->listen($alice, $track, 40_000)), 'own tracks count too');

        $this->em()->getConnection()->executeStatement("UPDATE listen SET created_at = created_at - interval '181 seconds'");
        self::assertSame(['counted' => true, 'playCount' => 3], $this->json($this->listen($bob, $track, 60_000)), 'window elapsed');
        self::assertSame(3, $this->reload($track)->getPlayCount());
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM listen'));
        $affinity = $this->queued('async', RecomputeAffinity::class);
        self::assertSame([$bob->getId()->toRfc4122(), $alice->getId()->toRfc4122()], array_map(static fn (RecomputeAffinity $m): string => $m->userId, $affinity), 'deduplicated per user');
    }

    /** W-26 F13 (ADR 010): a listen counts only against a fresh, unconsumed stream grant. */
    public function testListenNeedsAFreshGrantFromStreamUrl(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $db = $this->em()->getConnection();

        self::assertSame(['counted' => false, 'playCount' => 0], $this->json($this->listen($bob, $track, 60_000, grantAgeS: null)), 'no stream-url, no count');

        // stream-url issues the grant; listenedMs cannot exceed the time elapsed since (+ 2 s).
        self::assertSame(200, $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $bob)->getStatusCode());
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM listen_grant'));
        self::assertSame(['counted' => false, 'playCount' => 0], $this->json($this->listen($bob, $track, 60_000, grantAgeS: null)), 'played 60 s within a few ms');
        self::assertSame(0, (int) $db->fetchOne('SELECT count(*) FROM listen_grant'), 'consumed even when not counted');
        self::assertSame(['counted' => false, 'playCount' => 0], $this->json($this->listen($bob, $track, 60_000, grantAgeS: null)), 'grant cannot be replayed');

        // A re-fetch keeps the original issued_at; a stale grant (> duration + 30 min) does not count.
        $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $bob);
        $db->executeStatement("UPDATE listen_grant SET issued_at = issued_at - interval '70 seconds'");
        $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $bob);
        self::assertSame(['counted' => true, 'playCount' => 1], $this->json($this->listen($bob, $track, 60_000, grantAgeS: null)), 'issued_at kept by the re-fetch');
        self::assertSame(['counted' => false, 'playCount' => 1], $this->json($this->listen($alice, $track, 60_000, grantAgeS: 180 + 1801)), 'stale grant');
    }

    /** W-26 F13: at most 20 counted listens per (user, track) over a rolling 24 h. */
    public function testDailyCapPerUserAndTrack(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $db = $this->em()->getConnection();
        for ($i = 1; $i <= 20; ++$i) {
            $db->executeStatement(
                "INSERT INTO listen (id, user_id, track_id, source, listened_ms, created_at) VALUES (?, ?, ?, 'player', 60000, now() - make_interval(mins => ?))",
                [Uuid::v7()->toRfc4122(), $bob->getId()->toRfc4122(), $track->getId()->toRfc4122(), 10 * $i],
            );
        }

        self::assertFalse($this->json($this->listen($bob, $track, 60_000))['counted'], '20 in the last 24 h');
        $db->executeStatement("UPDATE listen SET created_at = created_at - interval '24 hours' WHERE created_at = (SELECT min(created_at) FROM listen)");
        self::assertTrue($this->json($this->listen($bob, $track, 60_000))['counted'], 'the oldest left the window');
        self::assertTrue($this->json($this->listen($alice, $track, 60_000))['counted'], 'per user');
    }

    public function testValidationAndVisibility(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $track = $this->createTrack($artist);
        $url = '/api/v1/tracks/'.$track->getId().'/listens';

        $this->assertProblem($this->api('POST', $url, $bob, ['json' => ['listenedMs' => 40_000, 'source' => 'player', 'postId' => '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $url, $bob, ['json' => ['listenedMs' => 40_000, 'source' => 'feed']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $url, $bob, ['json' => ['listenedMs' => -1, 'source' => 'player']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $url, $bob, ['json' => ['listenedMs' => 3_600_001, 'source' => 'player']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $url, $bob, ['json' => ['source' => 'player']]), 422, 'validation_failed');

        $processing = $this->createTrack($artist, 'P', ready: false);
        $this->assertProblem($this->listen($alice, $processing, 40_000), 409, 'track_not_ready');
        $this->assertProblem($this->listen($bob, $processing, 40_000), 404, 'not_found');
    }

    /** Reports a listen, after planting a grant issued $grantAgeS seconds ago (null: use the grant in place, if any). */
    private function listen(User $as, Track $track, int $ms, ?int $grantAgeS = 600): ResponseInterface
    {
        if (null !== $grantAgeS) {
            $this->em()->getConnection()->executeStatement(
                'INSERT INTO listen_grant (user_id, track_id, issued_at) VALUES (?, ?, now() - make_interval(secs => ?))
                 ON CONFLICT (user_id, track_id) DO UPDATE SET issued_at = EXCLUDED.issued_at',
                [$as->getId()->toRfc4122(), $track->getId()->toRfc4122(), $grantAgeS],
            );
        }

        return $this->api('POST', '/api/v1/tracks/'.$track->getId().'/listens', $as, ['json' => ['listenedMs' => $ms, 'source' => 'player']]);
    }
}
