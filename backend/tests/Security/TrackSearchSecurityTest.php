<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Pagination\CursorCodec;
use App\Search\SearchProvider;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * fb-1109 QA (docs/handoff/fb-1109-qa.md): `GET /search?type=tracks` — existence oracle through the
 * "mine first" ranking, forged/replayed cursors, malformed parameters, caps; FB-13 (affinity after commit).
 */
final class TrackSearchSecurityTest extends SecurityTestCase
{
    private function search(?User $as, string $query): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->api('GET', '/api/v1/search?'.$query, $as);
    }

    /** @return list<string> */
    private function ids(\Symfony\Contracts\HttpClient\ResponseInterface $r): array
    {
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        return array_column($this->json($r)['tracks']['items'], 'id');
    }

    /**
     * A like survives the track going private/hidden/failed or its owner being suspended; the likers answer is
     * then identical to someone who never liked it (no ranking oracle, no leak in any section).
     */
    public function testStaleLikeOfANowInvisibleTrackIsNotAnOracle(): void
    {
        $liker = $this->createUser('liker');
        $stranger = $this->createUser('stranger');
        $owner = $this->createUser('owner');
        $artist = $this->createArtist($owner, 'Owner Band');
        $public = $this->createTrack($artist, 'Quartz');
        $cases = [];
        foreach (["visibility = 'private'", "moderation = 'hidden'", "status = 'failed', failure_reason = 'no_audio'", "status = 'processing'"] as $set) {
            $t = $this->createTrack($artist, 'Quartz');
            self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$t->getId().'/like', $liker)->getStatusCode());
            $cases[$set] = $t->getId()->toRfc4122();
        }
        $db = $this->em()->getConnection();
        foreach ($cases as $set => $id) {
            $db->executeStatement("UPDATE track SET $set WHERE id = ?", [$id]);
        }

        $mine = $this->ids($this->search($liker, 'type=tracks&q=quartz'));
        $theirs = $this->ids($this->search($stranger, 'type=tracks&q=quartz'));
        self::assertSame([$public->getId()->toRfc4122()], $mine);
        self::assertSame($mine, $theirs);
        // the raw body of the two callers differs only by the per-viewer flags, never by extra/missing rows
        self::assertCount(1, $this->json($this->search($liker, 'type=tracks&q=Owner%20Band'))['tracks']['items']);

        // the whole owner suspended after the like
        $liked = $this->createTrack($this->createArtist($this->createUser('soon'), 'Soon Band'), 'Quartz Two');
        $this->api('PUT', '/api/v1/tracks/'.$liked->getId().'/like', $liker);
        $db->executeStatement('UPDATE app_user SET suspended_at = now() WHERE username = ?', ['soon']);
        self::assertNotContains($liked->getId()->toRfc4122(), $this->ids($this->search($liker, 'type=tracks&q=quartz')));
        self::assertNotContains($liked->getId()->toRfc4122(), $this->ids($this->search($liker, 'type=tracks&q=soon')));
    }

    /** A track that turns private between two pages never shows up on the next one; nothing repeats. */
    public function testTrackTurningPrivateBetweenPagesDisappears(): void
    {
        $me = $this->createUser('mee');
        $artist = $this->createArtist($this->createUser('owner'), 'Band');
        $tracks = [];
        for ($i = 0; $i < 4; ++$i) {
            $tracks[] = $this->createTrack($artist, 'Delta '.$i)->getId()->toRfc4122();
        }
        $p1 = $this->json($this->search($me, 'type=tracks&q=delta&limit=2'));
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE track SET visibility = 'private'");
        $p2 = $this->search($me, 'type=tracks&q=delta&limit=2&cursor='.$p1['tracks']['nextCursor']);
        self::assertSame([], $this->ids($p2));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedQueries(): iterable
    {
        yield 'q array' => ['type=tracks&q[]=ab'];
        yield 'q map' => ['type=tracks&q[a]=ab'];
        yield 'type array' => ['type[]=tracks&q=ab'];
        yield 'limit array' => ['type=tracks&q=ab&limit[]=1'];
        yield 'cursor array' => ['type=tracks&q=ab&cursor[]=x'];
        yield 'limit zero' => ['type=tracks&q=ab&limit=0'];
        yield 'limit 51' => ['type=tracks&q=ab&limit=51'];
        yield 'limit negative' => ['type=tracks&q=ab&limit=-1'];
        yield 'limit text' => ['type=tracks&q=ab&limit=1e1'];
        yield 'limit huge' => ['type=tracks&q=ab&limit=99999999999999999999'];
        yield 'q 101' => ['type=tracks&q=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
        yield 'q blank' => ['type=tracks&q=%20%20%20'];
        yield 'q one char padded' => ['type=tracks&q=%20a%20'];
        yield 'no type' => ['q=ab'];
        yield 'type empty' => ['type=&q=ab'];
        yield 'type case' => ['type=TRACKS&q=ab'];
        yield 'type sql' => ['type=tracks%27%3B--&q=ab'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedQueries')]
    public function testMalformedParametersAreClientErrorsNeverServerErrors(string $query): void
    {
        $r = $this->search($this->createUser('mee'), $query);
        self::assertContains($r->getStatusCode(), [400, 422], $query.' → '.$r->getContent(false));
        self::assertStringStartsWith('application/problem+json', $r->getHeaders(false)['content-type'][0] ?? '');
        self::assertStringNotContainsString('SQLSTATE', $r->getContent(false));
        self::assertStringNotContainsString('Stack trace', $r->getContent(false));
    }

    /** Hostile text that is valid input: never an error, never a match-all; multibyte case folding included. */
    public function testHostileButValidQueriesAreHarmless(): void
    {
        $me = $this->createUser('mee');
        $this->createTrack($this->createArtist($this->createUser('owner'), 'Band'), 'Plain song');
        foreach (["'; DROP TABLE track;--", "' OR '1'='1", '" OR 1=1 --', '\\\\', 'ab\\\\', '%a', 'a_', '{{7*7}}', '<script>', 'İİ', 'ẞẞ', 'ǅǅ', "\u{202E}ab", str_repeat('é', 100), '$1', ':q', '??', 'a:b', "a\nb", "a\tb"] as $q) {
            $r = $this->search($me, 'type=tracks&q='.rawurlencode($q));
            self::assertSame(200, $r->getStatusCode(), bin2hex($q).' '.$r->getContent(false));
            self::assertSame([], $this->ids($r), bin2hex($q).' must not match "Plain song"');
        }
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM track'));
    }

    /** Forged, tampered, re-typed and out-of-range cursors all end in invalid_cursor (400). */
    public function testForgedAndReplayedCursors(): void
    {
        $me = $this->createUser('mee');
        $artist = $this->createArtist($this->createUser('owner'), 'Band');
        for ($i = 0; $i < 4; ++$i) {
            $this->createTrack($artist, 'Gamma '.$i);
        }
        $good = $this->json($this->search($me, 'type=tracks&q=gamma&limit=2'))['tracks']['nextCursor'];
        self::assertIsString($good);
        $codec = static::getContainer()->get(CursorCodec::class);

        $bad = [
            'flipped char' => substr($good, 0, 5).('A' === $good[5] ? 'B' : 'A').substr($good, 6),
            'truncated' => substr($good, 0, -3),
            'doubled' => $good.$good,
            'empty mac' => substr($good, 0, -22),
            'unsigned json' => rtrim(strtr(base64_encode('{"v":1,"k":["tracks","gamma",0]}'), '+/', '-_'), '=').str_repeat('A', 22),
            'garbage' => '!!!!'.str_repeat('a', 30),
            'negative offset' => $codec->encode(['tracks', 'gamma', -1]),
            'string offset' => $codec->encode(['tracks', 'gamma', '2']),
            'float offset' => $codec->encode(['tracks', 'gamma', 2.5]),
            'null offset' => $codec->encode(['tracks', 'gamma', null]),
            'bool offset' => $codec->encode(['tracks', 'gamma', true]),
            'offset at cap' => $codec->encode(['tracks', 'gamma', SearchProvider::MAX_RESULTS]),
            'offset beyond cap' => $codec->encode(['tracks', 'gamma', PHP_INT_MAX]),
            'empty keys' => $codec->encode([]),
            'wrong type' => $codec->encode(['users', 'gamma', 2]),
            'wrong q' => $codec->encode(['tracks', 'delta', 2]),
            'q with accents unfolded' => $codec->encode(['tracks', 'GAMMA', 2]),
            'foreign keyset (listing cursor)' => $codec->encode(['2026-01-01T00:00:00Z', '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e']),
        ];
        foreach ($bad as $label => $cursor) {
            $r = $this->search($me, 'type=tracks&q=gamma&cursor='.rawurlencode($cursor));
            self::assertSame(400, $r->getStatusCode(), $label.' '.$r->getContent(false));
            $this->assertProblem($r, 400, 'invalid_cursor');
        }

        // Replay: same q in another case or padded is the same query, so the cursor keeps working; another q does not.
        self::assertCount(2, $this->ids($this->search($me, 'type=tracks&q=%20GAMMA%20&limit=2&cursor='.$good)));
        $this->assertProblem($this->search($me, 'type=tracks&q=gamm&cursor='.$good), 400, 'invalid_cursor');
        $this->assertProblem($this->search($me, 'type=users&q=gamma&cursor='.$good), 400, 'invalid_cursor');
        // A cursor of another user is just a position in the same ranking: it reveals nothing and works.
        $other = $this->createUser('other');
        self::assertCount(2, $this->ids($this->search($other, 'type=tracks&q=gamma&limit=2&cursor='.$good)));
    }

    /** At most 200 results are reachable, page after page, and the last page has no cursor. */
    public function testResultsAreCappedAt200(): void
    {
        $me = $this->createUser('mee');
        $artist = $this->createArtist($this->createUser('owner'), 'Band');
        $conn = $this->em()->getConnection();
        $template = $this->createTrack($artist, 'Cap 000');
        $tid = $template->getId()->toRfc4122();
        // clone the row 209 times (same artist/owner) without going through the API
        $cols = implode(', ', array_map(static fn (string $c): string => '"'.$c.'"', $conn->fetchFirstColumn("SELECT column_name FROM information_schema.columns WHERE table_name = 'track' AND column_name NOT IN ('id', 'title') ORDER BY ordinal_position")));
        $conn->executeStatement(
            "INSERT INTO track (id, title, $cols) SELECT gen_random_uuid(), 'Cap '||g, $cols FROM track, generate_series(1, 209) g WHERE track.id = ?",
            [$tid],
        );
        self::assertSame(210, (int) $conn->fetchOne('SELECT count(*) FROM track'));

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $r = $this->search($me, 'type=tracks&q=cap&limit=50'.($cursor ? '&cursor='.$cursor : ''));
            self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
            $page = $this->json($r)['tracks'];
            array_push($seen, ...array_column($page['items'], 'id'));
            $cursor = $page['nextCursor'];
            ++$pages;
        } while (null !== $cursor && $pages < 10);
        self::assertCount(200, $seen);
        self::assertCount(200, array_unique($seen), 'no duplicate across pages');
        self::assertNull($cursor);
        self::assertSame(4, $pages);
    }

    public function testSearchIsRateLimitedAndNeedsAuthentication(): void
    {
        $me = $this->createUser('mee');
        $this->assertProblem($this->search(null, 'type=tracks&q=ab'), 401, 'unauthenticated');
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.search_minute_user');
        $factory->create($me->getId()->toRfc4122())->consume(60);
        $this->assertProblem($this->search($me, 'type=tracks&q=ab'), 429, 'rate_limited');
        $this->assertProblem($this->search($me, 'type=users&q=ab'), 429, 'rate_limited');
    }

    /**
     * Search results never expose more than GET /tracks/{id}: no stream/audio key, no email, no owner internals.
     */
    public function testResultItemsCarryNoInternalFields(): void
    {
        $me = $this->createUser('mee');
        $this->createTrack($this->createArtist($this->createUser('owner'), 'Band'), 'Epsilon');
        $body = $this->search($me, 'type=tracks&q=epsilon')->getContent(false);
        foreach (['@', 'storage', 'audioKey', 'audio_key', 'password', 'suspended', 'sourceKey'] as $needle) {
            self::assertStringNotContainsString($needle, $body, $needle);
        }
    }

    /** FB-13: after a dropped affinity request the connection is clean; later likes/listens still work and count once. */
    public function testFailingAffinityLockLeavesAUsableConnectionAndExactCounters(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $t1 = $this->createTrack($artist, 'One');
        $t2 = $this->createTrack($artist, 'Two');
        $db = $this->em()->getConnection();
        $db->executeStatement('ALTER TABLE lock_keys ADD CONSTRAINT fb13_lock_fails CHECK (false) NOT VALID');

        foreach ([$t1, $t2] as $t) {
            $r = $this->api('PUT', '/api/v1/tracks/'.$t->getId().'/like', $bob);
            self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
            self::assertSame(1, $this->json($r)['likeCount']);
            // idempotent replay: still 1, no double count
            self::assertSame(1, $this->json($this->api('PUT', '/api/v1/tracks/'.$t->getId().'/like', $bob))['likeCount']);
        }
        self::assertSame(2, (int) $db->fetchOne('SELECT count(*) FROM track_like WHERE user_id = ?', [$bob->getId()->toRfc4122()]));
        self::assertSame(2, (int) $db->fetchOne('SELECT sum(like_count) FROM track'));
        self::assertSame([], $this->queued('async', \App\Message\RecomputeAffinity::class));
    }

    /** A replayed (uncounted) listen neither bumps play_count nor asks for a recompute. */
    public function testReplayedListenCountsOnceAndQueuesAtMostOneRecompute(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $db = $this->em()->getConnection();
        $counted = [];
        for ($i = 0; $i < 3; ++$i) {
            $db->executeStatement(
                'INSERT INTO listen_grant (user_id, track_id, issued_at) VALUES (?, ?, now() - interval \'600 seconds\')
                 ON CONFLICT (user_id, track_id) DO UPDATE SET issued_at = EXCLUDED.issued_at',
                [$bob->getId()->toRfc4122(), $track->getId()->toRfc4122()],
            );
            $counted[] = $this->json($this->api('POST', '/api/v1/tracks/'.$track->getId().'/listens', $bob, ['json' => ['listenedMs' => 31_000, 'source' => 'player']]))['counted'];
        }
        // anti-replay window: only the first one counts
        self::assertSame([true, false, false], $counted);
        self::assertSame(1, $this->reload($track)->getPlayCount());
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM listen'));
        self::assertCount(1, $this->queued('async', \App\Message\RecomputeAffinity::class), 'deduplicated, once');
    }
}
