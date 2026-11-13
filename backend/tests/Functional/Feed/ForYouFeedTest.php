<?php

declare(strict_types=1);

namespace App\Tests\Functional\Feed;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Pagination\CursorCodec;
use App\Recommendation\AffinityRecomputer;
use App\Recommendation\TrendingRecomputer;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;

/**
 * W-39 / ADR 011: "Pour toi" v1 behaviour. Ranker-agnostic (W-40 keeps them); only helpers feed the precomputed signals (setCounts → trending_score, likeTrack → user_affinity).
 * Views are written with `clock_timestamp()`: under DAMA the whole test is one transaction, so
 * `now()` would predate the session start.
 */
final class ForYouFeedTest extends WaveApiTestCase
{
    private const string URL = '/api/v1/feed/for-you';

    public function testFollowedAuthorRanksAboveUnfollowed(): void
    {
        [$viewer, $followed, $other] = [$this->createUser('viewer'), $this->createUser('followed'), $this->createUser('other')];
        $this->follow($viewer, $followed);
        $a = $this->post($other, $this->track($other));
        $b = $this->post($followed, $this->track($followed));
        $this->setCounts($a, likes: 5);
        $this->setCounts($b, likes: 5);

        self::assertSame([(string) $b->getId(), (string) $a->getId()], $this->ids($this->page($viewer)));
    }

    public function testMostLikedGenreRanksAboveOtherGenre(): void
    {
        [$viewer, $x, $y] = [$this->createUser('viewer'), $this->createUser('xavier'), $this->createUser('yann')];
        $liked = $this->track($y, genre: 'rap');
        $this->likeTrack($viewer, $liked);
        $pop = $this->post($x, $this->track($x));
        $rap = $this->post($x, $this->track($x, genre: 'rap'));
        $this->age($pop, 5);
        $this->age($rap, 5);

        $ids = $this->ids($this->page($viewer));
        self::assertSame([(string) $rap->getId(), (string) $pop->getId()], $ids);
    }

    public function testRecomputeJobsFillAffinityAndTrending(): void
    {
        [$viewer, $x] = [$this->createUser('viewer'), $this->createUser('xavier')];
        $rap = $this->track($x, genre: 'rap');
        $pop = $this->track($x);
        $post = $this->post($x, $rap);
        $this->likeTrack($viewer, $rap); // 3 at age 0 on genre rap and on the artist
        $this->sql("INSERT INTO listen (id, user_id, track_id, source, listened_ms, created_at) VALUES (:i, :u, :t, 'player', 40000, clock_timestamp() - interval '30 days')", ['i' => '0192f0a0-0000-7000-8000-0000000000aa', 'u' => $viewer->getId()->toRfc4122(), 't' => $pop->getId()->toRfc4122()]);
        static::getContainer()->get(AffinityRecomputer::class)->recompute($viewer->getId());
        /** @var array<string, float> $rows */
        $rows = $this->em()->getConnection()->fetchAllKeyValue("SELECT kind || ':' || key, score FROM user_affinity WHERE user_id = :u", ['u' => $viewer->getId()->toRfc4122()]);
        self::assertEqualsWithDelta(1.0, $rows['genre:rap'], 1e-9);
        self::assertEqualsWithDelta(0.5 / 3.0, $rows['genre:pop'], 0.01); // one listen (1) halved by a 30-day age, over the like (3)

        $this->sql('UPDATE post SET trending_score = 99 WHERE id = :id', ['id' => $post->getId()->toRfc4122()]); // stale score
        static::getContainer()->get(TrendingRecomputer::class)->recompute();
        $score = fn (string $t, string $id): float => (float) $this->em()->getConnection()->fetchOne("SELECT trending_score FROM $t WHERE id = :id", ['id' => $id]);
        self::assertEqualsWithDelta(3.0, $score('track', $rap->getId()->toRfc4122()), 0.01); // the like; 0 post signals reset the stale 99
        self::assertSame(0.0, $score('post', $post->getId()->toRfc4122()));
    }

    public function testPopularPostOfTodayRanksAboveUnpopularOne(): void
    {
        [$viewer, $x] = [$this->createUser('viewer'), $this->createUser('xavier')];
        $quiet = $this->post($x, $this->track($x));
        $popular = $this->post($x, $this->track($x));
        $this->setCounts($popular, likes: 100);

        self::assertSame([(string) $popular->getId(), (string) $quiet->getId()], $this->ids($this->page($viewer)));
    }

    public function testColdStartIsOrderedByFreshnessAndSameSeedGivesSameOrder(): void
    {
        $viewer = $this->createUser('viewer');
        $posts = [];
        foreach (['ann', 'ben', 'cat', 'dan'] as $i => $name) {
            $u = $this->createUser($name);
            $posts[$i] = $this->post($u, $this->track($u));
        }
        $this->age($posts[1], 240); // 10 days: 0.10·(1 − 0.1) > jitter 0.05
        $first = $this->page($viewer, 10);
        $ids = $this->ids($first);
        self::assertCount(4, $ids);
        self::assertSame((string) $posts[1]->getId(), $ids[3]);
        self::assertNull($first['nextCursor']);

        // Same seed and session start → same order: restart the session from the top with the first page's seed.
        $codec = static::getContainer()->get(CursorCodec::class);
        $page = $this->page($viewer, 1);
        $payload = $codec->decodePayload((string) $page['nextCursor']);
        $restart = $codec->encodePayload(['k' => ['9', 'ffffffff-ffff-ffff-ffff-ffffffffffff']] + $payload);
        $again = $this->page($viewer, 10, $restart);
        $second = $this->page($viewer, 10, $restart);
        self::assertCount(4, $this->ids($again));
        self::assertSame($this->ids($page)[0], $this->ids($again)[0]);
        self::assertSame($this->ids($again), $this->ids($second));
    }

    public function testExclusionsInBothPhases(): void
    {
        [$viewer, $alice, $sus, $bob] = [$this->createUser('viewer'), $this->createUser('alice'), $this->createUser('suspect'), $this->createUser('bob')];
        $this->follow($viewer, $alice);
        $publicTrack = $this->track($alice);
        $ok = $this->post($alice, $publicTrack);

        $excluded = [
            $this->post($viewer, $this->track($viewer)),                                           // own
            $this->post($alice, $publicTrack, ready: false),                                      // processing
            $this->hidePost($this->post($alice, $publicTrack)),                                   // hidden
            $this->post($bob, $this->track($alice, Visibility::Followers)),                       // others' clip of a non-public track
            $this->post($bob, $this->track($alice, allowClips: false)),                           // allowClips off
            $this->post($sus, $this->track($sus)),                                                // author suspended (below)
            $this->post($bob, $this->track($bob, Visibility::Followers)),                         // followers, not following
            $this->post($bob, $this->track($bob, Visibility::CloseFriends)),                      // close friends, not in list
            $this->post($alice, $this->track($alice, Visibility::CloseFriends)),                  // follows alice, not in her list
            $this->post($alice, $this->track($alice, Visibility::Private)),                       // private
        ];
        $allowedRestricted = $this->post($alice, $this->track($alice, Visibility::Followers));
        $this->suspend($sus);
        // Half the excluded posts (and one allowed) are "seen" before the session: phase s must exclude them too.
        foreach ([$excluded[1], $excluded[2], $excluded[3], $excluded[5], $excluded[7], $excluded[9], $allowedRestricted] as $i => $p) {
            $this->view($viewer, $p, \sprintf('-%d hours', 10 - $i));
        }

        $all = $this->drain($viewer, 1);
        sort($all);
        $expected = [(string) $ok->getId(), (string) $allowedRestricted->getId()];
        sort($expected);
        self::assertSame($expected, $all);
    }

    public function testAuthorCapAppliesAcrossPhases(): void
    {
        [$viewer, $a, $b] = [$this->createUser('viewer'), $this->createUser('alice'), $this->createUser('bob')];
        $track = $this->track($a);
        [$a1, $a2, $a3] = [$this->post($a, $track), $this->post($a, $track), $this->post($a, $track)];
        $b1 = $this->post($b, $this->track($b));
        $this->view($viewer, $a3, '-1 hour'); // a3 is phase s, a1 + a2 phase u

        $page = $this->page($viewer, 10);
        $ids = $this->ids($page);
        self::assertCount(3, $ids);
        self::assertNotContains((string) $a3->getId(), $ids);
        self::assertEqualsCanonicalizing([(string) $a1->getId(), (string) $a2->getId(), (string) $b1->getId()], $ids);
        self::assertNull($page['nextCursor']);
    }

    public function testCapAppliesOnEveryPageAndSkippedRowsAreDropped(): void
    {
        $viewer = $this->createUser('viewer');
        $a = $this->createUser('alice');
        $track = $this->track($a);
        for ($i = 0; $i < 6; ++$i) {
            $this->post($a, $track);
        }
        $first = $this->page($viewer, 2);
        self::assertCount(2, $this->ids($first));
        self::assertNotNull($first['nextCursor']);
        $seen = $this->ids($first);
        $cursor = $first['nextCursor'];
        while (null !== $cursor) {
            $p = $this->page($viewer, 2, $cursor);
            self::assertLessThanOrEqual(2, \count($p['items']));
            $seen = [...$seen, ...$this->ids($p)];
            $cursor = $p['nextCursor'];
        }
        self::assertSame($seen, array_values(array_unique($seen)));
    }

    public function testAllSeenFallsBackToLeastRecentlySeenFirst(): void
    {
        [$viewer, $a, $b, $c] = [$this->createUser('viewer'), $this->createUser('alice'), $this->createUser('bob'), $this->createUser('carl')];
        $pa = $this->post($a, $this->track($a));
        $pb = $this->post($b, $this->track($b));
        $pc = $this->post($c, $this->track($c));
        $this->view($viewer, $pb, '-3 hours');
        $this->view($viewer, $pc, '-2 hours');
        $this->view($viewer, $pa, '-1 hour');

        $page = $this->page($viewer, 10);
        self::assertSame([(string) $pb->getId(), (string) $pc->getId(), (string) $pa->getId()], $this->ids($page));
        self::assertNull($page['nextCursor']);
    }

    public function testNoRepeatWithinASessionWhenViewsAreReportedBetweenPages(): void
    {
        $viewer = $this->createUser('viewer');
        $all = [];
        foreach (['ann', 'ben', 'cat', 'dan', 'eve', 'fay'] as $i => $name) {
            $u = $this->createUser($name);
            $p = $this->post($u, $this->track($u));
            $all[] = (string) $p->getId();
            if ($i >= 4) {
                $this->view($viewer, $p, '-'.$i.' hours'); // seen in an earlier session
            }
        }
        $collected = [];
        $cursor = null;
        $guard = 0;
        do {
            $page = $this->page($viewer, 2, $cursor);
            foreach ($page['items'] as $item) {
                $collected[] = $item['id'];
                $this->viewNow($viewer, $item['id']); // the client reports what it showed
            }
            $cursor = $page['nextCursor'];
        } while (null !== $cursor && ++$guard < 20);

        self::assertSame($collected, array_values(array_unique($collected)));
        self::assertEqualsCanonicalizing($all, $collected);
    }

    public function testOnlyOwnPostsGivesEmptyPage(): void
    {
        $viewer = $this->createUser('viewer');
        $this->post($viewer, $this->track($viewer));
        $page = $this->page($viewer);
        self::assertSame([], $page['items']);
        self::assertNull($page['nextCursor']);
    }

    public function testItemsAreFullPostsWithClip(): void
    {
        [$viewer, $a] = [$this->createUser('viewer'), $this->createUser('alice')];
        $p = $this->post($a, $this->track($a));
        $item = $this->page($viewer)['items'][0];
        self::assertSame((string) $p->getId(), $item['id']);
        self::assertIsArray($item['clip']);
        self::assertSame('alice', $item['author']['username']);
    }

    public function testLimitAndCursorErrors(): void
    {
        $viewer = $this->createUser('viewer');
        $this->assertProblem($this->api('GET', self::URL.'?limit=21', $viewer), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', self::URL.'?limit=0', $viewer), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', self::URL), 401, 'unauthenticated');

        $codec = static::getContainer()->get(CursorCodec::class);
        $good = ['v' => 1, 'r' => 'sql1', 'p' => 'u', 'seed' => 'abcd0123', 'now' => '2026-11-10T08:00:00.000000Z', 'k' => ['0.5', '0192f0a0-0000-7000-8000-000000000000']];
        self::assertSame(200, $this->api('GET', self::URL.'?cursor='.$codec->encodePayload($good), $viewer)->getStatusCode());
        foreach ([
            ['r' => 'simple1'] + $good, // W-40: cursors of the retired SimpleFeedRanker restart the session
            ['p' => 'x'] + $good,
            ['k' => ['not-a-date', $good['k'][1]], 'p' => 's'] + $good,
            ['seed' => 'zz'] + $good,
        ] as $bad) {
            $this->assertProblem($this->api('GET', self::URL.'?cursor='.$codec->encodePayload($bad), $viewer), 400, 'invalid_cursor');
        }
        $tampered = $codec->encodePayload($good);
        $tampered[5] = 'A' === $tampered[5] ? 'B' : 'A';
        $this->assertProblem($this->api('GET', self::URL.'?cursor='.$tampered, $viewer), 400, 'invalid_cursor');
        // A plain keyset cursor (another endpoint) is not a for-you cursor.
        $this->assertProblem($this->api('GET', self::URL.'?cursor='.$codec->encode(['x', 'y']), $viewer), 400, 'invalid_cursor');
    }

    // ───────────────────────────── helpers ─────────────────────────────

    /** @return array{items: list<array<string, mixed>>, nextCursor: string|null} */
    private function page(User $viewer, int $limit = 10, ?string $cursor = null): array
    {
        $r = $this->api('GET', self::URL.'?limit='.$limit.(null !== $cursor ? '&cursor='.$cursor : ''), $viewer);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        /** @var array{items: list<array<string, mixed>>, nextCursor: string|null} $data */
        $data = $this->json($r);

        return $data;
    }

    /**
     * @param array{items: list<array<string, mixed>>, nextCursor: string|null} $page
     *
     * @return list<string>
     */
    private function ids(array $page): array
    {
        return array_map(static fn (array $i): string => (string) $i['id'], $page['items']);
    }

    /** @return list<string> every id of a whole session */
    private function drain(User $viewer, int $limit): array
    {
        $ids = [];
        $cursor = null;
        $guard = 0;
        do {
            $page = $this->page($viewer, $limit, $cursor);
            $ids = [...$ids, ...$this->ids($page)];
            $cursor = $page['nextCursor'];
        } while (null !== $cursor && ++$guard < 50);
        self::assertNull($cursor);

        return $ids;
    }

    private function track(User $owner, Visibility $visibility = Visibility::Public, bool $allowClips = true, string $genre = 'pop'): Track
    {
        $track = $this->createTrack($this->createArtist($owner, 'Artist '.bin2hex(random_bytes(3))), 'Song', true, $visibility, $allowClips);
        if ('pop' !== $genre) {
            $this->sql('UPDATE track SET genre_id = :g WHERE id = :id', ['g' => $genre, 'id' => $track->getId()->toRfc4122()]);
        }

        return $track;
    }

    private function post(User $author, Track $track, bool $ready = true): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $this->em()->flush();
        if ($ready) {
            $post->setClipKey('audio/clips/'.$post->getId()->toRfc4122().'.m4a');
            $this->em()->flush();
            self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        }
        // Distinct, strictly past creation times (equal ages, sub-second apart).
        $this->sql("UPDATE post SET created_at = clock_timestamp() - interval '1 second' WHERE id = :id", ['id' => $post->getId()->toRfc4122()]);
        $this->em()->refresh($post); // the UPDATEs bypass the unit of work

        return $post;
    }

    private function hidePost(Post $post): Post
    {
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = :id", ['id' => $post->getId()->toRfc4122()]);

        return $post;
    }

    private function age(Post $post, int $hours): void
    {
        $this->sql('UPDATE post SET created_at = created_at - make_interval(hours => :h) WHERE id = :id', ['h' => $hours, 'id' => $post->getId()->toRfc4122()]);
    }

    private function setCounts(Post $post, int $likes = 0): void
    {
        $this->sql('UPDATE post SET like_count = :l, trending_score = :t WHERE id = :id', ['l' => $likes, 't' => 3.0 * $likes, 'id' => $post->getId()->toRfc4122()]);
    }

    private function follow(User $follower, User $followed): void
    {
        $this->sql('INSERT INTO follow (follower_id, followed_id, created_at) VALUES (:a, :b, now())', ['a' => $follower->getId()->toRfc4122(), 'b' => $followed->getId()->toRfc4122()]);
    }

    private function likeTrack(User $user, Track $track): void
    {
        $this->sql('INSERT INTO track_like (user_id, track_id, created_at) VALUES (:u, :t, now())', ['u' => $user->getId()->toRfc4122(), 't' => $track->getId()->toRfc4122()]);
        static::getContainer()->get(AffinityRecomputer::class)->recompute($user->getId()); // W-40: the ranker reads user_affinity
    }

    private function suspend(User $user): void
    {
        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = :id', ['id' => $user->getId()->toRfc4122()]);
    }

    /** A view from before the session (relative to the real clock). */
    private function view(User $user, Post $post, string $ago): void
    {
        $this->sql(
            'INSERT INTO post_view (user_id, post_id, watched_ms, completed, first_seen_at, last_seen_at)
             VALUES (:u, :p, 1000, false, clock_timestamp() + CAST(:ago AS interval), clock_timestamp() + CAST(:ago AS interval))',
            ['u' => $user->getId()->toRfc4122(), 'p' => $post->getId()->toRfc4122(), 'ago' => $ago],
        );
    }

    /** POST /post-views semantics (DATA_MODEL §11 upsert) at the real current time. */
    private function viewNow(User $user, string $postId): void
    {
        $this->sql(
            'INSERT INTO post_view (user_id, post_id, watched_ms, completed, first_seen_at, last_seen_at)
             VALUES (:u, :p, 1000, false, clock_timestamp(), clock_timestamp())
             ON CONFLICT (user_id, post_id) DO UPDATE SET last_seen_at = clock_timestamp()',
            ['u' => $user->getId()->toRfc4122(), 'p' => $postId],
        );
    }

    /** @param array<string, mixed> $params */
    private function sql(string $sql, array $params): void
    {
        $this->em()->getConnection()->executeStatement($sql, $params);
    }
}
