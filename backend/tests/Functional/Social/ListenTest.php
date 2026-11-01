<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Entity\Track;
use App\Entity\User;
use App\Message\RecomputeAffinity;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-23: listens (DATA_MODEL §11). The test track lasts 180 s. */
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
        self::assertSame(['counted' => false, 'playCount' => 1], $this->json($this->listen($bob, $track, 120_000)), 'second within max(30 s, 90 s)');
        self::assertSame(['counted' => true, 'playCount' => 2], $this->json($this->listen($alice, $track, 40_000)), 'own tracks count too');

        $this->em()->getConnection()->executeStatement("UPDATE listen SET created_at = created_at - interval '91 seconds'");
        self::assertSame(['counted' => true, 'playCount' => 3], $this->json($this->listen($bob, $track, 60_000)), 'window elapsed');
        self::assertSame(3, $this->reload($track)->getPlayCount());
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM listen'));
        $affinity = $this->queued('async', RecomputeAffinity::class);
        self::assertSame([$bob->getId()->toRfc4122(), $alice->getId()->toRfc4122()], array_map(static fn (RecomputeAffinity $m): string => $m->userId, $affinity), 'deduplicated per user');
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

    private function listen(User $as, Track $track, int $ms): ResponseInterface
    {
        return $this->api('POST', '/api/v1/tracks/'.$track->getId().'/listens', $as, ['json' => ['listenedMs' => $ms, 'source' => 'player']]);
    }
}
