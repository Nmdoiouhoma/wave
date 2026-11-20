<?php

declare(strict_types=1);

namespace App\Tests\Functional\Discover;

use App\Discover\DiscoverQuery;
use App\Entity\Follow;
use App\Entity\Genre;
use App\Entity\Listen;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\TrackLike;
use App\Entity\User;
use App\Enum\ListenSource;
use App\Enum\Visibility;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-87 `GET /discover`: each exclusion and each ordering rule of the contract, and the batch hydration. */
final class DiscoverHomeTest extends WaveApiTestCase
{
    public function testRequiresAuthenticationAndAlwaysReturnsTheSixSections(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/discover'), 401, 'unauthenticated');

        $body = $this->discover($this->createUser('viewer'));
        self::assertSame(['forYouTracks', 'newTracks', 'trendingTracks', 'genres', 'suggestedUsers', 'trendingHashtags'], array_keys($body));
        self::assertSame([], $body['forYouTracks']);
        self::assertSame([], $body['newTracks']);
        self::assertSame([], $body['trendingTracks']);
        self::assertCount(12, $body['genres'], 'every genre ranks, even without tracks');
        self::assertSame([], $body['suggestedUsers']);
        self::assertSame([], $body['trendingHashtags']);
    }

    public function testTrendingTracksArePublicVisibleOnlyByScoreThenIdAndHydratedPerCaller(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $banned = $this->createUser('banned');
        $artist = $this->createArtist($owner, 'A');
        $low = $this->createTrack($artist, 'Low');
        $tieA = $this->createTrack($artist, 'TieA');
        $tieB = $this->createTrack($artist, 'TieB');
        $top = $this->createTrack($artist, 'Top');
        $own = $this->createTrack($this->createArtist($me, 'Mine'), 'Own');
        $excluded = [
            $this->createTrack($artist, 'Followers', visibility: Visibility::Followers),
            $this->createTrack($artist, 'Close', visibility: Visibility::CloseFriends),
            $this->createTrack($artist, 'Private', visibility: Visibility::Private),
            $this->createTrack($artist, 'Processing', ready: false),
            $hidden = $this->createTrack($artist, 'Hidden'),
            $this->createTrack($this->createArtist($banned, 'B'), 'Banned'),
        ];
        $this->sql("UPDATE track SET moderation = 'hidden' WHERE id = :id", ['id' => $this->id($hidden)]);
        $this->sql('UPDATE track SET trending_score = 100');
        $this->score($top, 50);
        $this->score($tieA, 10);
        $this->score($tieB, 10);
        $this->score($own, 5);
        $this->score($low, 1);
        $this->managed($banned)->suspend();
        $this->em()->persist(new TrackLike($this->managed($me), $this->managed($top)));
        $this->em()->flush();

        $trending = $this->discover($me)['trendingTracks'];
        $ties = [$this->id($tieA), $this->id($tieB)];
        rsort($ties);
        self::assertSame([$this->id($top), ...$ties, $this->id($own), $this->id($low)], array_column($trending, 'id'), 'score desc, id desc; own public track included');
        foreach ($excluded as $track) {
            self::assertNotContains($this->id($track), array_column($trending, 'id'));
        }
        self::assertTrue($trending[0]['likedByMe']);
        self::assertFalse($trending[1]['likedByMe']);
        self::assertTrue($trending[3]['isOwner']);
        self::assertSame('public', $trending[0]['visibility']);
    }

    public function testCachedIdsAreRecheckedSoAHiddenTrackOrSuspendedOwnerDisappearsAtOnce(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $other = $this->createUser('other');
        $hidden = $this->createTrack($this->createArtist($owner, 'A'), 'Hidden');
        $banned = $this->createTrack($this->createArtist($other, 'B'), 'Banned');
        $kept = $this->createTrack($this->createArtist($owner, 'C'), 'Kept');
        self::assertCount(3, $this->discover($me)['trendingTracks']);

        $this->sql("UPDATE track SET moderation = 'hidden' WHERE id = :id", ['id' => $this->id($hidden)]);
        $this->managed($other)->suspend();
        $this->em()->flush();

        $body = $this->discover($me, clearCache: false);
        self::assertSame([$this->id($kept)], array_column($body['trendingTracks'], 'id'));
        self::assertSame([$this->id($kept)], array_column($body['newTracks'], 'id'));
        self::assertNotContains($this->id($banned), array_column($body['newTracks'], 'id'));
    }

    public function testNewTracksNewestFirstExcludingOwnAndNonPublicOnes(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $artist = $this->createArtist($owner, 'A');
        $old = $this->createTrack($artist, 'Old');
        $sameA = $this->createTrack($artist, 'SameA');
        $sameB = $this->createTrack($artist, 'SameB');
        $newest = $this->createTrack($artist, 'Newest');
        $own = $this->createTrack($this->createArtist($me, 'Mine'), 'Own');
        $restricted = $this->createTrack($artist, 'Followers', visibility: Visibility::Followers);
        $processing = $this->createTrack($artist, 'Processing', ready: false);
        $this->sql("UPDATE track SET created_at = now() - interval '3 days' WHERE id = :id", ['id' => $this->id($old)]);
        $this->sql("UPDATE track SET created_at = now() - interval '2 days' WHERE id IN (:a, :b)", ['a' => $this->id($sameA), 'b' => $this->id($sameB)]);
        $this->sql("UPDATE track SET created_at = now() - interval '1 day' WHERE id = :id", ['id' => $this->id($newest)]);
        $this->em()->persist(new Follow($this->managed($me), $this->managed($owner)));
        $this->em()->flush();

        $ids = array_column($this->discover($me)['newTracks'], 'id');
        $same = [$this->id($sameA), $this->id($sameB)];
        rsort($same);
        self::assertSame([$this->id($newest), ...$same, $this->id($old)], $ids, 'created_at desc, id desc');
        foreach ([$own, $restricted, $processing] as $track) {
            self::assertNotContains($this->id($track), $ids, 'own, restricted (even to a follower) and unready tracks are excluded');
        }
    }

    public function testNewTracksFallBackToADirectQueryWhenTheCachedOnesAreTheCallersOwn(): void
    {
        $me = $this->createUser('viewer');
        $other = $this->createUser('other');
        $theirs = [];
        for ($i = 0; $i < 3; ++$i) {
            $theirs[] = $this->id($this->createTrack($this->createArtist($other, 'O'.$i), 'Theirs'.$i));
        }
        $this->sql("UPDATE track SET created_at = now() - interval '1 day'");
        $mine = $this->createArtist($me, 'Mine');
        for ($i = 0; $i < DiscoverQuery::NEW_CACHED; ++$i) {
            $this->createTrack($mine, 'Mine'.$i);
        }

        $ids = array_column($this->discover($me)['newTracks'], 'id');
        rsort($theirs);
        self::assertSame($theirs, $ids);
        self::assertCount(12, array_column($this->discover($other)['newTracks'], 'id'));
    }

    public function testGenresAffinityFirstThenPublicTrackCountThenName(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $artist = $this->createArtist($owner, 'A');
        foreach (['afro' => 3, 'jazz' => 2, 'blues' => 2] as $genre => $n) {
            for ($i = 0; $i < $n; ++$i) {
                $this->genre($this->createTrack($artist, $genre.$i), $genre);
            }
        }
        for ($i = 0; $i < 5; ++$i) {
            $this->genre($this->createTrack($artist, 'secret'.$i, visibility: Visibility::Followers), 'metal');
        }
        $this->affinity($me, 'genre', 'rnb', 0.4);
        $this->affinity($me, 'genre', 'jazz', 1.0);

        $genres = $this->discover($me)['genres'];
        $ids = array_column($genres, 'id');
        self::assertCount(12, $ids);
        self::assertSame(['jazz', 'rnb', 'afro', 'blues'], \array_slice($ids, 0, 4), 'affinity by score, then public track count; jazz not repeated');
        self::assertSame(['id' => 'jazz', 'name' => $this->genreName('jazz')], $genres[0]);
        self::assertNotContains('metal', \array_slice($ids, 0, 4), 'restricted tracks are not counted');

        $plain = array_column($this->discover($owner)['genres'], 'id');
        self::assertSame(['afro', 'blues', 'jazz'], \array_slice($plain, 0, 3), 'no affinity: track count, then name (Blues < Jazz)');
    }

    public function testSuggestedUsersExclusionsAndRanking(): void
    {
        $me = $this->createUser('viewer');
        $friend = $this->createUser('friend');
        $followed = $this->createUser('followed');
        $mutual2 = $this->createUser('mutualtwo');
        $mutual1 = $this->createUser('mutualone');
        $popular = $this->createUser('popular');
        $poster = $this->createUser('poster');
        $quiet = $this->createUser('quiet');
        $secret = $this->createUser('secret');
        $banned = $this->createUser('banned');
        $hiddenOnly = $this->createUser('hiddenonly');

        foreach ([$me, $friend, $followed, $mutual2, $mutual1, $popular, $banned] as $u) {
            $this->createTrack($this->createArtist($u, 'Artist of '.$u->getUsername()), 'T');
        }
        $this->createTrack($this->createArtist($secret, 'S'), 'T', visibility: Visibility::Followers);
        $hiddenTrack = $this->createTrack($this->createArtist($hiddenOnly, 'H'), 'T');
        $this->sql("UPDATE track SET moderation = 'hidden' WHERE id = :id", ['id' => $this->id($hiddenTrack)]);
        $publicTrack = $this->createTrack($this->createArtist($this->createUser('clipped'), 'C'), 'Clippable');
        $this->post($poster, $publicTrack);

        foreach ([[$me, $friend], [$me, $followed], [$friend, $mutual2], [$followed, $mutual2], [$friend, $mutual1], [$friend, $secret], [$friend, $banned]] as [$a, $b]) {
            $this->em()->persist(new Follow($this->managed($a), $this->managed($b)));
        }
        $this->em()->flush();
        $this->sql("UPDATE app_user SET followers_count = 50 WHERE username = 'popular'");
        $this->sql("UPDATE app_user SET followers_count = 0 WHERE username IN ('mutualtwo', 'mutualone', 'poster')");
        $this->managed($banned)->suspend();
        $this->em()->flush();

        $users = $this->discover($me)['suggestedUsers'];
        self::assertSame(
            ['mutualtwo', 'mutualone', 'popular', 'poster', 'clipped'],
            array_column($users, 'username'),
            'followees in common, then followers, then id; never me, a followed, suspended, restricted-only, hidden-only or content-less user'
        );
        self::assertSame(['id', 'username', 'displayName', 'avatarUrl', 'followersCount', 'isMe', 'isFollowedByMe'], array_keys($users[0]));
        self::assertFalse($users[0]['isFollowedByMe']);
        self::assertNotContains('quiet', array_column($this->discover($quiet)['suggestedUsers'], 'username'), 'never the caller');
    }

    public function testTrendingHashtagsRecentPublicPostsThenPostCountThenNameNeverEmpty(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $banned = $this->createUser('banned');
        $public = $this->createTrack($this->createArtist($owner, 'A'), 'Public');
        $noClips = $this->createTrack($this->createArtist($owner, 'B'), 'NoClips', allowClips: false);
        $restricted = $this->createTrack($this->createArtist($owner, 'C'), 'Restricted', visibility: Visibility::Followers);

        $this->tag($this->post($owner, $public), 'hot');
        $this->tag($this->post($owner, $public), 'hot', 'alpha');
        $this->tag($this->post($owner, $noClips), 'beta', 'alpha');
        $old = $this->post($owner, $public);
        $this->tag($old, 'zeta', 'beta');
        $this->sql("UPDATE post SET created_at = now() - interval '8 days' WHERE id = :id", ['id' => $this->id($old)]);
        $this->sql("UPDATE hashtag SET post_count = 9 WHERE name = 'zeta'");
        $this->tag($this->post($owner, $restricted), 'secret');
        $this->tag($this->post($me, $public, ready: false), 'pending');
        $hidden = $this->post($owner, $public);
        $this->tag($hidden, 'hiddentag');
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = :id", ['id' => $this->id($hidden)]);
        $this->tag($this->post($banned, $public), 'bannedtag');
        $this->tag($this->post($me, $public), 'clip');
        $this->tag($this->post($me, $noClips), 'noclip');
        $this->sql("INSERT INTO hashtag (id, name, post_count, created_at) VALUES (:id, 'empty', 4, now())", ['id' => Uuid::v7()->toRfc4122()]);
        $this->managed($banned)->suspend();
        $this->em()->flush();

        $tags = $this->discover($me)['trendingHashtags'];
        self::assertSame(
            ['alpha', 'hot', 'beta', 'clip', 'zeta'],
            array_column($tags, 'name'),
            '2 recent (same post_count: by name), then 1 recent by post_count, then 0 recent'
        );
        self::assertSame(['name' => 'zeta', 'postCount' => 9], $tags[4]);
        foreach (['secret', 'pending', 'hiddentag', 'bannedtag', 'noclip', 'empty'] as $never) {
            self::assertNotContains($never, array_column($tags, 'name'));
        }
    }

    public function testForYouMatchesAffinityWithReasonAndExcludesOwnLikedListenedAndNonPublic(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $loved = $this->createArtist($owner, 'Loved');
        $other = $this->createArtist($owner, 'Other');
        $lovedTracks = [];
        for ($i = 0; $i < 5; ++$i) {
            $lovedTracks[] = $this->createTrack($loved, 'Loved'.$i);
        }
        $jazz = $this->genre($this->createTrack($other, 'Jazz'), 'jazz');
        $jazzHot = $this->genre($this->createTrack($other, 'JazzHot'), 'jazz');
        $unrelated = $this->genre($this->createTrack($other, 'Unrelated'), 'metal');
        $liked = $this->genre($this->createTrack($other, 'Liked'), 'jazz');
        $listened = $this->genre($this->createTrack($other, 'Listened'), 'jazz');
        $restricted = $this->genre($this->createTrack($other, 'Restricted', visibility: Visibility::Followers), 'jazz');
        $own = $this->genre($this->createTrack($this->createArtist($me, 'Mine'), 'Own'), 'jazz');
        $this->score($jazzHot, 5);
        $this->score($lovedTracks[4], 3);
        $this->affinity($me, 'artist', $this->id($loved), 1.0);
        $this->affinity($me, 'genre', 'jazz', 0.5);
        $this->affinity($me, 'genre', 'pop', 0.2);
        $this->em()->persist(new TrackLike($this->managed($me), $this->managed($liked)));
        $this->em()->persist(new Listen($this->managed($me), $this->managed($listened), ListenSource::Player, 30_000));
        $this->em()->persist(new Follow($this->managed($me), $this->managed($owner)));
        $this->em()->flush();

        $items = $this->discover($me)['forYouTracks'];
        $ids = array_map(fn (array $i): string => $i['track']['id'], $items);
        $topLoved = [$this->id($lovedTracks[3]), $this->id($lovedTracks[2])];
        self::assertSame(
            [$this->id($lovedTracks[4]), ...$topLoved, $this->id($jazzHot), $this->id($jazz)],
            $ids,
            'weight desc, trending desc, id desc; at most 3 per artist (Loved tracks are also pop: weight = the higher)'
        );
        self::assertSame(['artist', 'Loved'], [$items[0]['reasonType'], $items[0]['reasonLabel']]);
        self::assertSame(['genre', $this->genreName('jazz')], [$items[3]['reasonType'], $items[3]['reasonLabel']]);
        self::assertSame(['track', 'reasonType', 'reasonLabel'], array_keys($items[0]));
        foreach ([$unrelated, $liked, $listened, $restricted, $own] as $track) {
            self::assertNotContains($this->id($track), $ids);
        }
        self::assertSame([], $this->discover($owner)['forYouTracks'], 'no affinity, no fallback');
    }

    public function testQueryCountDoesNotGrowWithTheNumberOfItems(): void
    {
        $me = $this->createUser('viewer');
        $owner = $this->createUser('owner');
        $this->tag($this->post($owner, $this->createTrack($this->createArtist($owner, 'A'), 'One')), 'first');
        $this->affinity($me, 'genre', 'pop', 1.0);
        static::getContainer()->get('cache.discover')->clear();
        $this->em()->clear();
        $small = $this->countQueries('GET', '/api/v1/discover', $me);

        for ($i = 0; $i < 8; ++$i) {
            $u = $this->createUser('user'.$i);
            $t = $this->createTrack($this->createArtist($u, 'Artist'.$i), 'T'.$i);
            $this->tag($this->post($u, $t), 'tag'.$i);
            $this->em()->persist(new TrackLike($this->managed($me), $this->managed($t)));
        }
        $this->em()->flush();
        static::getContainer()->get('cache.discover')->clear();
        $this->affinity($me, 'genre', 'rap', 0.5);

        $this->em()->clear();
        self::assertSame($small, $this->countQueries('GET', '/api/v1/discover', $me), 'one query per section, batch hydration');
    }

    /** @return array<array-key, mixed> */
    private function discover(User $as, bool $clearCache = true): array
    {
        if ($clearCache) {
            static::getContainer()->get('cache.discover')->clear();
        }
        $this->em()->clear();
        $r = $this->api('GET', '/api/v1/discover', $as);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        return $this->json($r);
    }

    private function id(object $entity): string
    {
        \assert($entity instanceof Track || $entity instanceof Post || $entity instanceof \App\Entity\Artist);

        return $entity->getId()->toRfc4122();
    }

    private function score(Track $track, float $score): void
    {
        $this->sql('UPDATE track SET trending_score = :s WHERE id = :id', ['s' => $score, 'id' => $this->id($track)]);
    }

    private function genre(Track $track, string $genre): Track
    {
        $this->sql('UPDATE track SET genre_id = :g WHERE id = :id', ['g' => $genre, 'id' => $this->id($track)]);

        return $track;
    }

    private function genreName(string $id): string
    {
        $genre = $this->em()->find(Genre::class, $id);
        self::assertNotNull($genre);

        return $genre->getName();
    }

    private function affinity(User $user, string $kind, string $key, float $score): void
    {
        $this->sql(
            'INSERT INTO user_affinity (user_id, kind, "key", score, updated_at) VALUES (:u, :k, :key, :s, now())',
            ['u' => $user->getId()->toRfc4122(), 'k' => $kind, 'key' => $key, 's' => $score],
        );
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

        return $post;
    }

    private function tag(Post $post, string ...$names): void
    {
        foreach ($names as $name) {
            $this->sql(
                'INSERT INTO hashtag (id, name, post_count, created_at) VALUES (:id, :name, 1, now())
                 ON CONFLICT (name) DO UPDATE SET post_count = hashtag.post_count + 1',
                ['id' => Uuid::v7()->toRfc4122(), 'name' => $name],
            );
            $this->sql('INSERT INTO post_hashtag (post_id, hashtag_id) SELECT :post, id FROM hashtag WHERE name = :name', ['post' => $this->id($post), 'name' => $name]);
        }
    }

    /** @param array<string, mixed> $params */
    private function sql(string $sql, array $params = []): void
    {
        $this->em()->getConnection()->executeStatement($sql, $params);
    }
}
