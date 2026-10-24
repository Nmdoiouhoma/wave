<?php

declare(strict_types=1);

namespace App\Tests\Functional\Stream;

use App\Entity\Track;
use App\Enum\Moderation;
use App\Enum\Visibility;
use App\Tests\Functional\WaveApiTestCase;

/** W-11: signed stream URL + X-Accel-Redirect (ADR 001). */
final class StreamTest extends WaveApiTestCase
{
    public function testSignedUrlThenAccelRedirect(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob', verified: false);
        $track = $this->createTrack($this->createArtist($alice));

        $before = time();
        $response = $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $bob);

        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
        $body = $this->json($response);
        self::assertSame(['url', 'expiresAt'], array_keys($body));
        self::assertStringStartsWith('http://wave.test/api/v1/stream/', $body['url']);
        $expires = new \DateTimeImmutable($body['expiresAt'])->getTimestamp();
        self::assertEqualsWithDelta($before + 300, $expires, 2);
        self::assertMatchesRegularExpression('/Z$/', $body['expiresAt']);

        $path = (string) parse_url($body['url'], \PHP_URL_PATH);
        $this->forgetCookies();
        foreach (['GET', 'HEAD'] as $method) {
            $stream = $this->api($method, $path);
            self::assertSame(200, $stream->getStatusCode(), $stream->getContent(false));
            $headers = $stream->getHeaders();
            self::assertSame('/_protected/private/audio/tracks/'.$track->getId().'.m4a', $headers['x-accel-redirect'][0]);
            self::assertSame('audio/mp4', $headers['content-type'][0]);
            self::assertSame('', $stream->getContent());
        }
    }

    public function testTamperedTokenIsForbiddenAndExpiredIsGone(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $path = $this->streamPath($alice, $track);
        [$payload, $mac] = explode('.', substr($path, \strlen('/api/v1/stream/')));

        $data = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        self::assertIsArray($data);
        $data['exp'] += 3600;
        $forged = rtrim(strtr(base64_encode((string) json_encode($data)), '+/', '-_'), '=');
        $this->assertProblem($this->api('GET', '/api/v1/stream/'.$forged.'.'.$mac), 403, 'stream_token_invalid');
        $this->assertProblem($this->api('GET', '/api/v1/stream/garbage'), 403, 'stream_token_invalid');
        $this->assertProblem($this->api('GET', '/api/v1/stream/'.$payload.'.'.strrev($mac)), 403, 'stream_token_invalid');

        $signer = new \App\Stream\StreamTokenSigner('test-stream-signing-key', -10, new \Symfony\Component\Clock\NativeClock());
        $expired = $signer->sign('t', $track->getId(), $alice->getId())['token'];
        $this->assertProblem($this->api('GET', '/api/v1/stream/'.$expired), 410, 'stream_token_expired');
    }

    public function testRevocationIsImmediate(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $bobPath = $this->streamPath($bob, $track);
        $alicePath = $this->streamPath($alice, $track);

        $track = $this->reload($track);
        $track->setVisibility(Visibility::Private);
        $this->em()->flush();

        $this->assertProblem($this->api('GET', $bobPath), 404, 'not_found');
        self::assertSame(200, $this->api('GET', $alicePath)->getStatusCode(), 'the owner still plays it');

        $track = $this->reload($track);
        $track->setVisibility(Visibility::Public);
        $track->setModeration(Moderation::Hidden);
        $this->em()->flush();
        $this->assertProblem($this->api('GET', $bobPath), 404, 'not_found');

        $id = $track->getId()->toRfc4122();
        $this->em()->getConnection()->executeStatement('DELETE FROM track WHERE id = ?', [$id]);
        $this->assertProblem($this->api('GET', $alicePath), 404, 'not_found');
    }

    public function testStreamUrlRules(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $processing = $this->createTrack($artist, 'P', ready: false);
        $private = $this->createTrack($artist, 'Q', visibility: Visibility::Private);

        $this->assertProblem($this->api('GET', '/api/v1/tracks/'.$processing->getId().'/stream-url', $alice), 409, 'track_not_ready');
        $this->assertProblem($this->api('GET', '/api/v1/tracks/'.$processing->getId().'/stream-url', $bob), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/tracks/'.$private->getId().'/stream-url', $bob), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/tracks/'.$private->getId().'/stream-url'), 401, 'unauthenticated');
        self::assertSame(200, $this->api('GET', '/api/v1/tracks/'.$private->getId().'/stream-url', $alice)->getStatusCode());
    }

    public function testStreamEndpointIgnoresBearerAndIsNotRateLimited(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $path = $this->streamPath($alice, $track);

        self::assertSame(200, $this->api('GET', $path, null, ['headers' => ['Authorization' => 'Bearer garbage']])->getStatusCode());
        for ($i = 0; $i < 130; ++$i) {
            $last = $this->api('GET', $path);
        }
        self::assertSame(200, $last->getStatusCode());
    }

    private function streamPath(\App\Entity\User $viewer, Track $track): string
    {
        $response = $this->api('GET', '/api/v1/tracks/'.$track->getId().'/stream-url', $viewer);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));

        return (string) parse_url($this->json($response)['url'], \PHP_URL_PATH);
    }
}
