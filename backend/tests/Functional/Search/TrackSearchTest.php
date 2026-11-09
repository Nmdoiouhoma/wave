<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-29a (FB-15): track search (`GET /search?type=tracks`). */
final class TrackSearchTest extends WaveApiTestCase
{
    /** One track per visibility case, all titled exactly like the query. */
    public function testOnlyTracksTheCallerCanSeeAndPlayAreReturned(): void
    {
        $me = $this->createUser('searcher');
        $other = $this->createUser('other');
        $banned = $this->createUser('banned');
        $mine = $this->createArtist($me, 'Mine');
        $theirs = $this->createArtist($other, 'Theirs');

        $expected = [
            $this->createTrack($theirs, 'Zulu'),
            $this->createTrack($mine, 'Zulu', visibility: Visibility::Private),
        ];
        $never = [
            $this->createTrack($theirs, 'Zulu', visibility: Visibility::Private),
            $this->createTrack($theirs, 'Zulu', ready: false),
            $this->alter($this->createTrack($theirs, 'Zulu'), "status = 'failed', failure_reason = 'no_audio'"),
            $this->alter($this->createTrack($theirs, 'Zulu'), "moderation = 'hidden'"),
            $this->createTrack($mine, 'Zulu', ready: false),
            $this->alter($this->createTrack($mine, 'Zulu'), "status = 'failed', failure_reason = 'no_audio'"),
            $this->alter($this->createTrack($mine, 'Zulu'), "moderation = 'hidden'"),
            $this->createTrack($this->createArtist($banned, 'Banned'), 'Zulu'),
        ];
        $banned->suspend();
        $this->em()->flush();

        $ids = $this->ids($this->search($me, 'zulu'));
        self::assertEqualsCanonicalizing(array_map(static fn (Track $t): string => $t->getId()->toRfc4122(), $expected), $ids);
        foreach ($never as $track) {
            self::assertNotContains($track->getId()->toRfc4122(), $ids);
        }
    }

    public function testMatchingIsAccentInsensitiveWithPerWordPrefixAndTrigram(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $halo = $this->createTrack($this->createArtist($owner, 'Beyoncé', 'beyonce'), 'Halo');
        $nuit = $this->createTrack($this->createArtist($owner, 'Night Band'), 'La nuit');
        $midnight = $this->createTrack($this->createArtist($owner, 'Other Band'), 'Midnight');
        $this->createTrack($this->createArtist($owner, 'Nobody'), 'Unrelated');

        self::assertSame([$halo->getId()->toRfc4122()], $this->ids($this->search($me, 'beyonce')), 'artist name, accent-insensitive');
        self::assertSame([$nuit->getId()->toRfc4122()], $this->ids($this->search($me, 'NUIT')), 'prefix of a later word');
        self::assertSame([$midnight->getId()->toRfc4122()], $this->ids($this->search($me, 'mideight')), 'trigram');
        self::assertSame([], $this->ids($this->search($me, 'zzzz')));

        $body = $this->json($this->search($me, 'halo'));
        self::assertSame(['tracks'], array_keys($body));
        self::assertSame(['items', 'nextCursor'], array_keys($body['tracks']));
    }

    public function testRankingOwnLikedExactPrefixSimilarityPlayCount(): void
    {
        $me = $this->createUser('searcher');
        $other = $this->createUser('other');
        $artist = $this->createArtist($other, 'Band');
        $own = $this->createTrack($this->createArtist($me, 'Mine'), 'Marcy');
        $liked = $this->createTrack($artist, 'Marcy');
        $exact = $this->createTrack($artist, 'Marco');
        $prefix = $this->createTrack($artist, 'Marcopolo');
        $similar = $this->createTrack($artist, 'Marc');
        $popular = $this->createTrack($artist, 'Marcy');
        $plain = $this->createTrack($artist, 'Marcy');
        $this->em()->getConnection()->executeStatement('UPDATE track SET play_count = 50 WHERE id = ?', [$popular->getId()->toRfc4122()]);
        self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$liked->getId().'/like', $me)->getStatusCode());

        self::assertSame(
            array_map(static fn (Track $t): string => $t->getId()->toRfc4122(), [$own, $liked, $exact, $prefix, $similar, $popular, $plain]),
            $this->ids($this->search($me, 'marco')),
        );
    }

    public function testFlagsAreCorrectWithoutNPlusOne(): void
    {
        $me = $this->createUser('searcher');
        $other = $this->createUser('other');
        $own = $this->createTrack($this->createArtist($me, 'Mine'), 'Echo mine');
        $liked = $this->createTrack($this->createArtist($other, 'Theirs'), 'Echo liked', allowClips: false);
        $this->api('PUT', '/api/v1/tracks/'.$liked->getId().'/like', $me);
        for ($i = 0; $i < 6; ++$i) {
            $this->createTrack($this->createArtist($this->createUser('owner'.$i), 'Artist '.$i), 'Echo '.$i);
        }

        $items = $this->json($this->search($me, 'echo'))['tracks']['items'];
        self::assertSame([$own->getId()->toRfc4122(), $liked->getId()->toRfc4122()], [$items[0]['id'], $items[1]['id']]);
        self::assertSame([false, true, true], [$items[0]['likedByMe'], $items[0]['isOwner'], $items[0]['canClip']]);
        self::assertSame([true, false, false], [$items[1]['likedByMe'], $items[1]['isOwner'], $items[1]['canClip']]);
        self::assertSame([false, false, true], [$items[2]['likedByMe'], $items[2]['isOwner'], $items[2]['canClip']]);

        self::assertSame(
            $this->countQueries('GET', '/api/v1/search?type=tracks&q=echo&limit=2', $me),
            $this->countQueries('GET', '/api/v1/search?type=tracks&q=echo&limit=8', $me),
        );
    }

    public function testValidationPaginationAndCursorBinding(): void
    {
        $me = $this->createUser('searcher');
        $this->createUser('paulie');
        $artist = $this->createArtist($this->createUser('owner'), 'Band');
        for ($i = 0; $i < 5; ++$i) {
            $this->createTrack($artist, 'Paul '.$i);
        }

        $this->assertProblem($this->search($me, 'p'), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/search?type=artists&q=paul', $me), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/search?type=tracks&q=paul'), 401, 'unauthenticated');

        $p1 = $this->json($this->search($me, 'paul', '&limit=3'));
        self::assertCount(3, $p1['tracks']['items']);
        $p2 = $this->json($this->search($me, 'paul', '&limit=3&cursor='.$p1['tracks']['nextCursor']));
        self::assertCount(2, $p2['tracks']['items']);
        self::assertNull($p2['tracks']['nextCursor']);
        self::assertSame([], array_intersect(array_column($p1['tracks']['items'], 'id'), array_column($p2['tracks']['items'], 'id')));

        // The cursor is bound to q and to type, both ways.
        $cursor = $p1['tracks']['nextCursor'];
        $this->assertProblem($this->search($me, 'paulo', '&cursor='.$cursor), 400, 'invalid_cursor');
        $this->assertProblem($this->api('GET', '/api/v1/search?type=users&q=paul&cursor='.$cursor, $me), 400, 'invalid_cursor');
        $users = $this->json($this->api('GET', '/api/v1/search?type=users&q=paul&limit=1', $me))['users'];
        self::assertCount(1, $users['items']);
        $codec = static::getContainer()->get(\App\Pagination\CursorCodec::class);
        $this->assertProblem($this->search($me, 'paul', '&cursor='.$codec->encode(['users', 'paul', 1])), 400, 'invalid_cursor');
        $this->assertProblem($this->search($me, 'paul', '&cursor='.$codec->encode(['tracks', 'paul', 200])), 400, 'invalid_cursor');
    }

    private function alter(Track $track, string $set): Track
    {
        $this->em()->getConnection()->executeStatement('UPDATE track SET '.$set.' WHERE id = ?', [$track->getId()->toRfc4122()]);

        return $track;
    }

    private function search(User $as, string $q, string $extra = ''): ResponseInterface
    {
        return $this->api('GET', '/api/v1/search?type=tracks&q='.rawurlencode($q).$extra, $as);
    }

    /** @return list<string> */
    private function ids(ResponseInterface $r): array
    {
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        return array_column($this->json($r)['tracks']['items'], 'id');
    }
}
