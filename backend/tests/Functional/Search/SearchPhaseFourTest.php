<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Pagination\CursorCodec;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;

/** W-41: artists, playlists and hashtags in `GET /search`, the overview without `type`, hashtag pages. */
final class SearchPhaseFourTest extends WaveApiTestCase
{
    public function testArtistsMatchWithoutAccentsByPrefixAndByTrigram(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $beyonce = $this->createArtist($owner, 'Beyoncé', 'beyonce')->getId()->toRfc4122();
        $daft = $this->createArtist($owner, 'Daft Punk')->getId()->toRfc4122();
        $lofi = $this->createArtist($owner, 'Lofi Girl')->getId()->toRfc4122();
        $this->createArtist($owner, 'Unrelated');

        self::assertSame([$beyonce], $this->ids($me, 'artists', 'beyonce'), 'accent-insensitive');
        self::assertSame([$beyonce], $this->ids($me, 'artists', 'BEYONCÉ'));
        self::assertSame([$lofi], $this->ids($me, 'artists', 'lof'), 'prefix');
        self::assertSame([$daft], $this->ids($me, 'artists', 'pun'), 'prefix of a later word');
        self::assertSame([$daft], $this->ids($me, 'artists', 'daft pu'), 'every token as a prefix');
        self::assertSame([$daft], $this->ids($me, 'artists', 'daft pnk'), 'trigram');
        self::assertSame([], $this->ids($me, 'artists', 'zzzz'));

        $body = $this->json($this->search($me, 'type=artists&q=daft'));
        self::assertSame(['artists'], array_keys($body));
        self::assertSame(['items', 'nextCursor'], array_keys($body['artists']));
        self::assertSame('Daft Punk', $body['artists']['items'][0]['name']);
        self::assertSame('owner', $body['artists']['items'][0]['owner']['username']);
    }

    public function testHiddenArtistsAndArtistsOfSuspendedOwnersAreNeverReturned(): void
    {
        $me = $this->createUser('searcher');
        $other = $this->createUser('other');
        $banned = $this->createUser('banned');
        $visible = $this->createArtist($other, 'Quartz One');
        $hidden = $this->createArtist($other, 'Quartz Two');
        $ownHidden = $this->createArtist($me, 'Quartz Three');
        $this->createArtist($banned, 'Quartz Four');
        $this->sql("UPDATE artist SET moderation = 'hidden' WHERE id IN (:a, :b)", ['a' => $hidden->getId()->toRfc4122(), 'b' => $ownHidden->getId()->toRfc4122()]);
        $banned->suspend();
        $this->em()->flush();

        self::assertSame([$visible->getId()->toRfc4122()], $this->ids($me, 'artists', 'quartz'));
        self::assertSame([$visible->getId()->toRfc4122()], array_column($this->json($this->search($me, 'q=quartz'))['artists']['items'], 'id'));
    }

    public function testArtistRankingExactThenPrefixThenSimilarity(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $similar = $this->createArtist($owner, 'Marcos')->getId()->toRfc4122();
        $prefix = $this->createArtist($owner, 'Marco Polo Band')->getId()->toRfc4122();
        $exact = $this->createArtist($owner, 'Marco')->getId()->toRfc4122();

        self::assertSame([$exact, $similar, $prefix], $this->ids($me, 'artists', 'marco'), 'exact, then full-text matches by similarity');
        // Trigram only (contract: similarity >= 0.3): `marco` (0.63) is closer to `marcoz` than `marcos` (0.56).
        self::assertSame([$exact, $similar], $this->ids($me, 'artists', 'marcoz'), 'trigram only, by similarity');
    }

    public function testPlaylistsMatchAndOnlyPublicVisibleOnesAreReturned(): void
    {
        $me = $this->createUser('searcher');
        $other = $this->createUser('other');
        $banned = $this->createUser('banned');
        $public = $this->createPlaylist($other, 'Soirée Été')->getId()->toRfc4122();
        $described = $this->createPlaylist($other, 'Mix')->getId()->toRfc4122();
        $this->sql("UPDATE playlist SET description = 'Pour une soirée calme' WHERE id = :id", ['id' => $described]);
        $private = $this->createPlaylist($other, 'Soirée privée', false)->getId()->toRfc4122();
        $ownPrivate = $this->createPlaylist($me, 'Soirée perso', false)->getId()->toRfc4122();
        $hidden = $this->createPlaylist($other, 'Soirée masquée')->getId()->toRfc4122();
        $this->sql("UPDATE playlist SET moderation = 'hidden' WHERE id = :id", ['id' => $hidden]);
        $suspended = $this->createPlaylist($banned, 'Soirée bannie')->getId()->toRfc4122();
        $banned->suspend();
        $this->em()->flush();

        self::assertSame([$public, $described], $this->ids($me, 'playlists', 'soiree'), 'name (A) before description (B)');
        self::assertSame([$public, $described], $this->ids($me, 'playlists', 'soir'), 'prefix');
        self::assertSame([$public], $this->ids($me, 'playlists', 'soiree ete'));
        self::assertSame([$public], $this->ids($me, 'playlists', 'soirre ete'), 'trigram');
        foreach (['soiree', 'privee', 'perso', 'masquee', 'bannie', 'soiree privee', 'soiree perso'] as $q) {
            $ids = $this->ids($me, 'playlists', $q);
            foreach ([$private, $ownPrivate, $hidden, $suspended] as $never) {
                self::assertNotContains($never, $ids, $q);
            }
        }
        $overview = array_column($this->json($this->search($me, 'q=soiree'))['playlists']['items'], 'id');
        self::assertSame([$public, $described], $overview);

        $body = $this->json($this->search($me, 'type=playlists&q=soiree'));
        self::assertSame(['playlists'], array_keys($body));
        self::assertSame('Soirée Été', $body['playlists']['items'][0]['name']);
    }

    public function testHashtagsMatchByPrefixAndTrigramAndOnlyWithAPlayablePost(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $banned = $this->createUser('banned');
        $public = $this->createTrack($this->createArtist($owner, 'A1'), 'Song');
        $restricted = $this->createTrack($this->createArtist($owner, 'A2'), 'Song', visibility: Visibility::Followers);
        $private = $this->createTrack($this->createArtist($owner, 'A3'), 'Song', visibility: Visibility::Private);

        $this->tag($this->post($owner, $public), 'lofi', 'beyoncé');
        $this->tag($this->post($owner, $restricted), 'lofisecret');
        $this->tag($this->post($owner, $private), 'lofiprivate');
        $this->tag($this->post($owner, $public, ready: false), 'lofipending');
        $hiddenPost = $this->post($owner, $public);
        $this->tag($hiddenPost, 'lofihidden');
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = :id", ['id' => $hiddenPost->getId()->toRfc4122()]);
        $this->tag($this->post($banned, $public), 'lofibanned');
        $banned->suspend();
        $this->em()->flush();

        self::assertSame(['lofi'], $this->names($me, 'lof'), 'prefix; nothing from restricted, private, pending, hidden or suspended posts');
        self::assertSame(['lofi'], $this->names($me, '#LOFI'), 'leading # and case ignored');
        self::assertSame(['lofi'], $this->names($me, 'lofy'), 'trigram');
        self::assertSame(['beyoncé'], $this->names($me, 'beyonce'), 'accent-tolerant');
        // `lofi` is a legitimate trigram neighbour (0.33 >= 0.3); the restricted tag itself never leaks.
        self::assertSame(['lofi'], $this->names($me, 'lofisecret'));
        self::assertSame([], $this->names($me, '##'));
        self::assertEqualsCanonicalizing(['lofi', 'lofisecret', 'lofiprivate'], $this->names($owner, 'lof'), 'the owner is in the audience of their own tracks');

        $body = $this->json($this->search($me, 'type=hashtags&q=lofi'));
        self::assertSame(['hashtags'], array_keys($body));
        self::assertSame([['name' => 'lofi', 'postCount' => 1]], $body['hashtags']['items']);
        self::assertSame(['lofi'], array_column($this->json($this->search($me, 'q=lof'))['hashtags']['items'], 'name'));
    }

    public function testOverviewHasFiveSectionsOfAtMostFiveItemsWithReusableCursors(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        for ($i = 1; $i <= 6; ++$i) {
            $track = $this->createTrack($this->createArtist($owner, 'Nebula Band '.$i), 'Nebula Song '.$i);
            $this->createUser('nebula'.$i);
            $this->createPlaylist($owner, 'Nebula Mix '.$i);
            $this->tag($this->post($owner, $track), 'nebula'.$i);
        }

        $body = $this->json($this->search($me, 'q=nebula'));
        self::assertSame(['tracks', 'artists', 'users', 'playlists', 'hashtags'], array_keys($body), 'no catalogue section (ADR 014 §3)');
        foreach ($body as $type => $section) {
            $type = (string) $type;
            self::assertCount(5, $section['items'], $type);
            self::assertIsString($section['nextCursor'], $type);
            $rest = $this->json($this->search($me, 'type='.$type.'&q=nebula&cursor='.$section['nextCursor']));
            self::assertSame([$type], array_keys($rest));
            self::assertCount(1, $rest[$type]['items'], $type);
            self::assertNull($rest[$type]['nextCursor'], $type);
            $key = 'hashtags' === $type ? 'name' : 'id';
            self::assertNotContains($rest[$type]['items'][0][$key], array_column($section['items'], $key), $type);
        }
        self::assertSame($body, $this->json($this->search($me, 'q=nebula&limit=50')), 'limit does not widen the overview');

        $empty = $this->json($this->search($me, 'q=zzzzzz'));
        foreach (['tracks', 'artists', 'users', 'playlists', 'hashtags'] as $type) {
            self::assertSame(['items' => [], 'nextCursor' => null], $empty[$type]);
        }

        $this->assertProblem($this->search($me, 'q=nebula&cursor='.$body['artists']['nextCursor']), 400, 'invalid_cursor');
        $this->assertProblem($this->search($me, 'type=playlists&q=nebula&cursor='.$body['artists']['nextCursor']), 400, 'invalid_cursor');
        $this->assertProblem($this->search($me, 'type=artists&q=nebul&cursor='.$body['artists']['nextCursor']), 400, 'invalid_cursor');
    }

    public function testTypedSearchPaginatesUpToTwoHundredResults(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $this->sql(
            "INSERT INTO playlist (id, owner_id, name, is_public, moderation, item_count, like_count, created_at, updated_at)
             SELECT gen_random_uuid(), :owner, 'Capped ' || i, true, 'visible', 0, 0, now(), now() FROM generate_series(1, 205) AS i",
            ['owner' => $owner->getId()->toRfc4122()],
        );

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $this->json($this->search($me, 'type=playlists&q=capped&limit=50'.(null === $cursor ? '' : '&cursor='.$cursor)))['playlists'];
            $seen = [...$seen, ...array_column($page['items'], 'id')];
            $cursor = $page['nextCursor'];
            ++$pages;
        } while (null !== $cursor && $pages < 10);

        self::assertSame(4, $pages);
        self::assertCount(200, array_unique($seen));
        $codec = static::getContainer()->get(CursorCodec::class);
        $this->assertProblem($this->search($me, 'type=playlists&q=capped&cursor='.$codec->encode(['playlists', 'capped', 200])), 400, 'invalid_cursor');
        $this->assertProblem($this->search($me, 'type=playlists&q=capped&cursor='.$codec->encode(['playlists', 'capped', -1])), 400, 'invalid_cursor');
    }

    public function testInvalidInputIsRefusedAndTsquerySyntaxNeverBreaksTheQuery(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $artist = $this->createArtist($owner, 'AC DC');
        $this->createPlaylist($owner, "Rock'n'roll");

        foreach (['', 'type=tracks&', 'type=artists&', 'type=users&', 'type=playlists&', 'type=hashtags&'] as $prefix) {
            // ~27 searches per type: stay under the 60/min bucket, which is not what this test is about.
            static::getContainer()->get('limiter.search_minute_user')->create($me->getId()->toRfc4122())->reset();
            // minLength 2 (contract): one-character queries, tsquery syntax included, are refused.
            foreach (['a', "'", '\\'] as $short) {
                $this->assertProblem($this->search($me, $prefix.'q='.rawurlencode($short)), 422, 'validation_failed');
            }
            $this->assertProblem($this->search($me, $prefix.'q=%20%20'), 422, 'validation_failed');
            $this->assertProblem($this->search($me, $prefix.'q='.str_repeat('a', 101)), 422, 'validation_failed');
            self::assertSame(200, $this->search($me, $prefix.'q='.str_repeat('a', 100))->getStatusCode(), $prefix.' 100 chars');
            self::assertSame(200, $this->search($me, $prefix.'q='.rawurlencode(str_repeat('é ', 50)))->getStatusCode(), $prefix.' 100 chars, 50 tokens');
            foreach (['a & b', '!!', 'foo:*', "''", '\\\\', '(a|b)', 'a <-> b', 'a:b & !c', '%%', '__', "' :* &", '&&', ':*', "x' | 'y", 'a\\\' & b', '<2>', "\u{FF05}\u{FF3F}", 'http://a.b/c?d=e', 'a@b.co', '-1.5e3'] as $q) {
                $r = $this->search($me, $prefix.'q='.rawurlencode($q));
                self::assertSame(200, $r->getStatusCode(), $prefix.' q='.$q.' → '.$r->getContent(false));
            }
        }
        foreach (['type=albums', 'type=', 'type=ARTISTS', 'type=artists%27--'] as $type) {
            $this->assertProblem($this->search($me, $type.'&q=rock'), 422, 'validation_failed');
        }
        $this->assertProblem($this->search($me, 'type[]=artists&q=rock'), 400, 'malformed_request');
        $this->assertProblem($this->search($me, 'q=rock&limit=0'), 400, 'malformed_request');
        $this->assertProblem($this->search($me, 'q=rock&cursor=garbage'), 400, 'invalid_cursor');
        $this->assertProblem($this->search(null, 'q=rock'), 401, 'unauthenticated');
        $this->assertProblem($this->search(null, 'type=artists&q=rock'), 401, 'unauthenticated');

        // Operators are plain text: `ac | zz` is not an OR, `ac:*` is not a wider prefix than `ac`.
        self::assertSame([], $this->ids($me, 'artists', 'qwxyzk | ac'), 'not an OR (a 4-letter token would be a 0.3 trigram neighbour)');
        self::assertSame([$artist->getId()->toRfc4122()], $this->ids($me, 'artists', 'ac & dc'));
        self::assertSame([$artist->getId()->toRfc4122()], $this->ids($me, 'artists', '!ac'));
        self::assertCount(1, $this->ids($me, 'playlists', "rock'n"));
        self::assertSame([], $this->ids($me, 'artists', '%%'), 'LIKE wildcards match nothing');
    }

    public function testGetHashtag(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $follower = $this->createUser('follower');
        $this->sql('INSERT INTO follow (follower_id, followed_id, created_at) VALUES (:f, :o, now())', ['f' => $follower->getId()->toRfc4122(), 'o' => $owner->getId()->toRfc4122()]);
        $public = $this->createTrack($this->createArtist($owner, 'A1'), 'Song');
        $restricted = $this->createTrack($this->createArtist($owner, 'A2'), 'Song', visibility: Visibility::Followers);
        $this->tag($this->post($owner, $public), 'lofi');
        $this->tag($this->post($owner, $public), 'lofi');
        $this->tag($this->post($owner, $restricted), 'afterparty');
        $hiddenPost = $this->post($owner, $public);
        $this->tag($hiddenPost, 'gone');
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = :id", ['id' => $hiddenPost->getId()->toRfc4122()]);

        $r = $this->api('GET', '/api/v1/hashtags/lofi', $me);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertSame(['name' => 'lofi', 'postCount' => 2], $this->json($r));
        self::assertSame('lofi', $this->json($this->api('GET', '/api/v1/hashtags/LoFi', $me))['name']);

        foreach (['unknown', 'afterparty', 'gone', 'lo-fi', 'lo%20fi', '%23lofi', str_repeat('a', 51), "lofi'%20OR%201=1--", '%25'] as $name) {
            $this->assertProblem($this->api('GET', '/api/v1/hashtags/'.$name, $me), 404, 'not_found');
            $this->assertProblem($this->api('GET', '/api/v1/hashtags/'.$name.'/posts', $me), 404, 'not_found');
        }
        self::assertSame(['name' => 'afterparty', 'postCount' => 1], $this->json($this->api('GET', '/api/v1/hashtags/afterparty', $follower)), 'in the audience');
        self::assertSame(200, $this->api('GET', '/api/v1/hashtags/afterparty', $owner)->getStatusCode());
        $this->assertProblem($this->api('GET', '/api/v1/hashtags/lofi'), 401, 'unauthenticated');
        $this->assertProblem($this->api('GET', '/api/v1/hashtags/lofi/posts'), 401, 'unauthenticated');
    }

    public function testHashtagPostsArePlayableOnesNewestFirst(): void
    {
        $me = $this->createUser('searcher');
        $owner = $this->createUser('owner');
        $banned = $this->createUser('banned');
        $public = $this->createTrack($this->createArtist($owner, 'A1'), 'Song');
        $restricted = $this->createTrack($this->createArtist($owner, 'A2'), 'Song', visibility: Visibility::Followers);
        $older = $this->tag($this->post($owner, $public), 'lofi');
        $middle = $this->tag($this->post($owner, $public), 'lofi', 'chill');
        $newer = $this->tag($this->post($owner, $public), 'lofi');
        $this->tag($this->post($owner, $public), 'chill');
        $onRestricted = $this->tag($this->post($owner, $restricted), 'lofi');
        $this->tag($this->post($owner, $public, ready: false), 'lofi');
        $hidden = $this->tag($this->post($owner, $public), 'lofi');
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = :id", ['id' => $hidden]);
        $this->tag($this->post($banned, $public), 'lofi');
        $banned->suspend();
        $this->em()->flush();
        foreach ([$older => 3, $middle => 2, $newer => 1] as $id => $minutes) {
            $this->sql('UPDATE post SET created_at = now() - make_interval(mins => :m) WHERE id = :id', ['m' => $minutes, 'id' => $id]);
        }

        $all = $this->json($this->api('GET', '/api/v1/hashtags/lofi/posts', $me));
        self::assertSame(['items', 'nextCursor'], array_keys($all));
        self::assertSame([$newer, $middle, $older], array_column($all['items'], 'id'));
        self::assertNull($all['nextCursor']);
        self::assertSame(['chill', 'lofi'], $all['items'][1]['hashtags']);
        self::assertContains($onRestricted, array_column($this->json($this->api('GET', '/api/v1/hashtags/lofi/posts', $owner))['items'], 'id'));

        $seen = [];
        $cursor = '';
        do {
            $page = $this->json($this->api('GET', '/api/v1/hashtags/lofi/posts?limit=1'.$cursor, $me));
            self::assertCount(1, $page['items']);
            $seen[] = $page['items'][0]['id'];
            $cursor = '&cursor='.$page['nextCursor'];
        } while (null !== $page['nextCursor'] && \count($seen) < 5);
        self::assertSame([$newer, $middle, $older], $seen);

        $this->assertProblem($this->api('GET', '/api/v1/hashtags/lofi/posts?cursor=garbage', $me), 400, 'invalid_cursor');
        $this->assertProblem($this->api('GET', '/api/v1/hashtags/lofi/posts?limit=51', $me), 400, 'malformed_request');
        $searchCursor = static::getContainer()->get(CursorCodec::class)->encode(['hashtags', 'lofi', 5]);
        $this->assertProblem($this->api('GET', '/api/v1/hashtags/lofi/posts?cursor='.$searchCursor, $me), 400, 'invalid_cursor');
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

    /** Attaches the hashtags to the post like the creation processor does; returns the post id. */
    private function tag(Post $post, string ...$names): string
    {
        $id = $post->getId()->toRfc4122();
        foreach ($names as $name) {
            $this->sql(
                'INSERT INTO hashtag (id, name, post_count, created_at) VALUES (:id, :name, 1, now())
                 ON CONFLICT (name) DO UPDATE SET post_count = hashtag.post_count + 1',
                ['id' => Uuid::v7()->toRfc4122(), 'name' => $name],
            );
            $this->sql('INSERT INTO post_hashtag (post_id, hashtag_id) SELECT :post, id FROM hashtag WHERE name = :name', ['post' => $id, 'name' => $name]);
        }

        return $id;
    }

    /** @param array<string, mixed> $params */
    private function sql(string $sql, array $params = []): void
    {
        $this->em()->getConnection()->executeStatement($sql, $params);
    }

    private function search(?User $as, string $query): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->api('GET', '/api/v1/search?'.$query, $as);
    }

    /** @return list<string> */
    private function ids(User $as, string $type, string $q): array
    {
        $r = $this->search($as, 'type='.$type.'&q='.rawurlencode($q));
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        return array_column($this->json($r)[$type]['items'], 'id');
    }

    /** @return list<string> */
    private function names(User $as, string $q): array
    {
        $r = $this->search($as, 'type=hashtags&q='.rawurlencode($q));
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        return array_column($this->json($r)['hashtags']['items'], 'name');
    }
}
