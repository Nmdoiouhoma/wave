<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Enum\FailureReason;
use App\Enum\Moderation;
use App\Enum\Visibility;

/**
 * W-26: phase 2 (playlists, follow, likes, listens, user search) — IDOR and 404-not-403 on
 * every playlist route, others' private/unready tracks, hostile inputs, no e-mail leak.
 */
final class SocialSecurityTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    /**
     * A private or hidden playlist of someone else is indistinguishable from a missing one on
     * every route (read, items, like, unlike, add, remove, reorder, update, delete).
     */
    public function testInvisiblePlaylistAnswersLikeAMissingOneEverywhere(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($bob, 'Bob Band', 'bob-band'));
        $private = $this->createPlaylist($alice, 'Secret', public: false);
        $hidden = $this->createPlaylist($alice, 'Hidden');
        $hidden->setModeration(Moderation::Hidden);
        $this->em()->flush();
        $tid = $track->getId()->toRfc4122();

        foreach ([$private->getId()->toRfc4122(), $hidden->getId()->toRfc4122(), self::UNKNOWN] as $pid) {
            $base = '/api/v1/playlists/'.$pid;
            $calls = [
                ['GET', $base, []],
                ['GET', $base.'/items', []],
                ['PUT', $base.'/like', []],
                ['DELETE', $base.'/like', []],
                ['POST', $base.'/items', ['json' => ['trackId' => $tid]]],
                ['DELETE', $base.'/items/'.$tid, []],
                ['PUT', $base.'/order', ['json' => ['trackIds' => []]]],
                ['PATCH', $base, ['json' => ['name' => 'pwned'], 'headers' => ['Content-Type' => 'application/merge-patch+json']]],
                ['DELETE', $base, []],
            ];
            foreach ($calls as [$method, $url, $options]) {
                $r = $this->api($method, $url, $bob, $options);
                $this->assertProblem($r, 404, 'not_found');
                self::assertStringNotContainsString('Secret', $r->getContent(false));
                self::assertStringNotContainsString('Hidden', $r->getContent(false));
            }
        }

        // Nothing changed, and the lists of others do not reveal them either.
        $conn = $this->em()->getConnection();
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM playlist_item'));
        self::assertSame(0, (int) $conn->fetchOne('SELECT count(*) FROM playlist_like'));
        self::assertSame(['Secret', 'Hidden'], array_map('strval', $conn->fetchFirstColumn('SELECT name FROM playlist ORDER BY created_at')));
        self::assertSame([], $this->json($this->api('GET', '/api/v1/users/alice/playlists', $bob))['items']);
    }

    /** A public playlist of someone else is read-only: every mutation → 403, nothing changes. */
    public function testPublicPlaylistOfSomeoneElseIsReadOnly(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $t1 = $this->createTrack($artist, 'One');
        $t2 = $this->createTrack($artist, 'Two');
        $playlist = $this->createPlaylist($alice);
        $base = '/api/v1/playlists/'.$playlist->getId();
        foreach ([$t1, $t2] as $t) {
            self::assertSame(201, $this->api('POST', $base.'/items', $alice, ['json' => ['trackId' => $t->getId()->toRfc4122()]])->getStatusCode());
        }

        $calls = [
            ['POST', $base.'/items', ['json' => ['trackId' => $this->createTrack($artist, 'Three')->getId()->toRfc4122()]]],
            ['DELETE', $base.'/items/'.$t1->getId(), []],
            ['PUT', $base.'/order', ['json' => ['trackIds' => [$t2->getId()->toRfc4122(), $t1->getId()->toRfc4122()]]]],
            ['PATCH', $base, ['json' => ['isPublic' => false], 'headers' => ['Content-Type' => 'application/merge-patch+json']]],
            ['DELETE', $base, []],
        ];
        foreach ($calls as [$method, $url, $options]) {
            $this->assertProblem($this->api($method, $url, $bob, $options), 403, 'forbidden');
        }

        $items = $this->json($this->api('GET', $base.'/items', $alice))['items'];
        self::assertSame([$t1->getId()->toRfc4122(), $t2->getId()->toRfc4122()], array_column($items, 'trackId'));
        self::assertTrue($this->json($this->api('GET', $base, $alice))['isPublic']);
        // Liking a visible playlist is fine for anyone.
        self::assertSame(200, $this->api('PUT', $base.'/like', $bob)->getStatusCode());
    }

    /**
     * Someone else's private, processing, failed or hidden track can be neither added to a
     * playlist, nor liked, nor reported as listened (404, existence not revealed).
     */
    public function testInvisibleTracksOfOthersCannotBeAddedLikedOrListened(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($bob, 'Bob Band', 'bob-band');
        $private = $this->createTrack($artist, 'Private', visibility: Visibility::Private);
        $processing = $this->createTrack($artist, 'Processing', ready: false);
        $failed = $this->createTrack($artist, 'Failed', ready: false);
        $failed->markFailed(FailureReason::InvalidMedia);
        $hidden = $this->createTrack($artist, 'Hidden');
        $hidden->setModeration(Moderation::Hidden);
        $this->em()->flush();
        $playlist = $this->createPlaylist($alice);
        $base = '/api/v1/playlists/'.$playlist->getId();

        foreach (['private' => $private, 'processing' => $processing, 'failed' => $failed, 'hidden' => $hidden] as $label => $t) {
            $id = $t->getId()->toRfc4122();
            $this->assertProblem($this->api('POST', $base.'/items', $alice, ['json' => ['trackId' => $id]]), 404, 'not_found');
            $this->assertProblem($this->api('PUT', '/api/v1/tracks/'.$id.'/like', $alice), 404, 'not_found');
            $this->assertProblem($this->api('POST', '/api/v1/tracks/'.$id.'/listens', $alice, ['json' => ['listenedMs' => 40_000, 'source' => 'player']]), 404, 'not_found');
            self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT like_count + play_count FROM track WHERE id = ?', [$id]), $label);
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM playlist_item'));

        // The owner sees their own processing track but still cannot add it (contract: 409).
        $mine = $this->createTrack($this->createArtist($alice), 'Mine', ready: false);
        $this->assertProblem($this->api('POST', $base.'/items', $alice, ['json' => ['trackId' => $mine->getId()->toRfc4122()]]), 409, 'track_not_ready');
    }

    /** Hostile bodies, paths and query strings: 4xx problems, never a 500. */
    public function testHostileInputsAreRejectedCleanly(): void
    {
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $track = $this->createTrack($artist);
        $playlist = $this->createPlaylist($alice);
        $base = '/api/v1/playlists/'.$playlist->getId();
        self::assertSame(201, $this->api('POST', $base.'/items', $alice, ['json' => ['trackId' => $track->getId()->toRfc4122()]])->getStatusCode());
        $tid = $track->getId()->toRfc4122();

        $bodies = [
            [$base.'/order', 'PUT', ['trackIds' => [[$tid]]]],
            [$base.'/order', 'PUT', ['trackIds' => [null]]],
            [$base.'/order', 'PUT', ['trackIds' => [123]]],
            [$base.'/order', 'PUT', ['trackIds' => $tid]],
            [$base.'/order', 'PUT', ['trackIds' => [strtoupper($tid), $tid]]],
            [$base.'/order', 'PUT', ['trackIds' => ['{'.$tid.'}']]],
            [$base.'/order', 'PUT', ['trackIds' => [$tid.'},{'.$tid]]],
            [$base.'/items', 'POST', ['trackId' => 123]],
            [$base.'/items', 'POST', ['trackId' => [$tid]]],
            [$base.'/items', 'POST', ['trackId' => "' OR 1=1 --"]],
            ['/api/v1/tracks/'.$tid.'/listens', 'POST', ['listenedMs' => '40000', 'source' => 'player']],
            ['/api/v1/tracks/'.$tid.'/listens', 'POST', ['listenedMs' => 4.0e4, 'source' => 'player']],
            ['/api/v1/tracks/'.$tid.'/listens', 'POST', ['listenedMs' => \PHP_INT_MAX, 'source' => 'player']],
            ['/api/v1/tracks/'.$tid.'/listens', 'POST', ['listenedMs' => 40_000, 'source' => 'radio']],
            ['/api/v1/playlists', 'POST', ['name' => ['x']]],
            ['/api/v1/playlists', 'POST', ['name' => 'ok', 'isPublic' => 'yes']],
            ['/api/v1/playlists', 'POST', ['name' => 'ok', 'description' => str_repeat('é', 301)]],
            ['/api/v1/playlists', 'POST', ['name' => str_repeat('x', 101)]],
        ];
        foreach ($bodies as [$url, $method, $body]) {
            $r = $this->api($method, $url, $alice, ['json' => $body]);
            self::assertContains($r->getStatusCode(), [400, 404, 409, 422], $method.' '.$url.' '.json_encode($body).' → '.$r->getContent(false));
        }

        foreach (['not-a-uuid', '..%2F..%2Fme', str_repeat('a', 5000), self::UNKNOWN.'%00'] as $bad) {
            $r = $this->api('GET', '/api/v1/playlists/'.$bad, $alice);
            self::assertContains($r->getStatusCode(), [400, 404], $bad);
        }
        foreach ([['GET', '/api/v1/users/'.str_repeat('a', 300).'/followers'], ['PUT', '/api/v1/users/al%27ice/follow'], ['GET', '/api/v1/users/al%27ice/following']] as [$method, $url]) {
            self::assertContains($this->api($method, $url, $alice)->getStatusCode(), [400, 404], $url);
        }
        foreach (['q[]=ab&type=users', 'q=ab&type[]=users', 'q=ab&type=users&limit[]=1', 'q=ab&type=users&cursor[]=x', 'q=ab&type=users&limit=51', 'q=ab&type=users&cursor=Zm9v'] as $qs) {
            $r = $this->api('GET', '/api/v1/search?'.$qs, $alice);
            self::assertContains($r->getStatusCode(), [400, 422], $qs.' → '.$r->getContent(false));
        }
    }

    /**
     * Bytes PostgreSQL refuses in text (NUL, invalid UTF-8) must give a 4xx, not a 500 that
     * leaks a driver error. FINDING if it fails (see docs/handoff/phase2-qa.md).
     */
    public function testNulBytesAndInvalidUtf8NeverReachPostgres(): void
    {
        $alice = $this->createUser('alice');
        $playlist = $this->createPlaylist($alice);

        $r = $this->api('POST', '/api/v1/playlists', $alice, ['json' => ['name' => "a\u{0}b"]]);
        self::assertContains($r->getStatusCode(), [400, 422], 'NUL in playlist name → '.$r->getStatusCode().' '.$r->getContent(false));
        $r = $this->api('PATCH', '/api/v1/playlists/'.$playlist->getId(), $alice, ['json' => ['description' => "x\u{0}"], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertContains($r->getStatusCode(), [400, 422], 'NUL in description → '.$r->getStatusCode());
        foreach (["ab\0cd", "ab\xff\xfe"] as $q) {
            $r = $this->api('GET', '/api/v1/search?type=users&q='.rawurlencode($q), $alice);
            self::assertContains($r->getStatusCode(), [400, 422], 'search q='.bin2hex($q).' → '.$r->getStatusCode());
        }
    }

    /**
     * `wave_unaccent()` folds fullwidth `％` / `＿` (and `﹪`) into the LIKE wildcards `%` / `_`.
     * The PHP-side escaping runs before that folding, so `q=％％` must not match everyone.
     * FINDING if it fails.
     */
    public function testUnicodeWildcardLookalikesDoNotWidenSearch(): void
    {
        $me = $this->createUser('searcher');
        $this->createUser('plain_user', displayName: 'Plain');
        foreach (['％％', '＿＿', '﹪﹪', 'p％'] as $q) {
            $r = $this->api('GET', '/api/v1/search?type=users&q='.rawurlencode($q), $me);
            self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
            self::assertNotContains('plain_user', array_column($this->json($r)['users']['items'], 'username'), $q.' must not match everyone');
        }
    }

    /** No e-mail, password hash or roles in any phase 2 payload that embeds other users. */
    public function testOtherUsersPrivateFieldsNeverLeak(): void
    {
        $alice = $this->createUser('alice', email: 'alice.secret@example.test');
        $bob = $this->createUser('bob', verified: false, email: 'bob.secret@example.test');
        $this->api('PUT', '/api/v1/users/alice/follow', $bob);
        $this->api('PUT', '/api/v1/users/bob/follow', $alice);
        $playlist = $this->createPlaylist($alice);
        $track = $this->createTrack($this->createArtist($alice));
        $this->api('POST', '/api/v1/playlists/'.$playlist->getId().'/items', $alice, ['json' => ['trackId' => $track->getId()->toRfc4122()]]);
        $this->api('PUT', '/api/v1/playlists/'.$playlist->getId().'/like', $bob);

        $urls = [
            '/api/v1/users/alice/followers', '/api/v1/users/alice/following', '/api/v1/users/bob/followers',
            '/api/v1/search?type=users&q=al', '/api/v1/playlists/'.$playlist->getId(),
            '/api/v1/playlists/'.$playlist->getId().'/items', '/api/v1/users/alice/playlists',
            '/api/v1/me/likes/playlists', '/api/v1/me/likes/tracks',
        ];
        foreach ($urls as $url) {
            $r = $this->api('GET', $url, $bob);
            self::assertSame(200, $r->getStatusCode(), $url);
            $body = $r->getContent(false);
            self::assertStringNotContainsString('secret@example.test', $body, $url);
            self::assertStringNotContainsStringIgnoringCase('"email', $body, $url);
            self::assertStringNotContainsString('"password', $body, $url);
            self::assertStringNotContainsString('"roles', $body, $url);
            self::assertStringNotContainsString('$argon2', $body, $url);
        }
    }

    /** Suspended accounts never show up in search; unverified ones do (contract). */
    public function testSearchExcludesSuspendedButNotUnverified(): void
    {
        $me = $this->createUser('searcher');
        $this->createUser('zorro_unverified', verified: false);
        $suspended = $this->createUser('zorro_suspended');
        $suspended->suspend();
        $this->em()->flush();

        $names = array_column($this->json($this->api('GET', '/api/v1/search?type=users&q=zorro', $me))['users']['items'], 'username');
        self::assertSame(['zorro_unverified'], $names);
        $names = array_column($this->json($this->api('GET', '/api/v1/search?type=users&q=zorro_suspended', $me))['users']['items'], 'username');
        self::assertSame([], $names, 'even on an exact username');
    }

    /**
     * W-29a: track search never surfaces what `GET /tracks/{id}` would hide, nor a suspended
     * owner's tracks, even on an exact title and even when the caller liked the track before it
     * became private or hidden (the "liked first" ranking must not bypass visibility).
     */
    public function testTrackSearchNeverLeaksInvisibleTracks(): void
    {
        $me = $this->createUser('searcher');
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice, 'Alice Band', 'alice-band');
        $public = $this->createTrack($artist, 'Kestrel');
        $private = $this->createTrack($artist, 'Kestrel');
        $hidden = $this->createTrack($artist, 'Kestrel');
        foreach ([$private, $hidden] as $track) {
            self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$track->getId().'/like', $me)->getStatusCode());
        }
        $banned = $this->createUser('banned');
        $bannedTrack = $this->createTrack($this->createArtist($banned, 'Banned Band', 'banned-band'), 'Kestrel');
        $this->api('PUT', '/api/v1/tracks/'.$bannedTrack->getId().'/like', $me);
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE track SET visibility = 'private' WHERE id = ?", [$private->getId()->toRfc4122()]);
        $db->executeStatement("UPDATE track SET moderation = 'hidden' WHERE id = ?", [$hidden->getId()->toRfc4122()]);
        $db->executeStatement('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$banned->getId()->toRfc4122()]);

        foreach (['kestrel', 'Kestrel', 'kestre', 'alice band', 'banned band'] as $q) {
            $r = $this->api('GET', '/api/v1/search?type=tracks&q='.rawurlencode($q), $me);
            self::assertSame(200, $r->getStatusCode(), $q);
            $ids = array_column($this->json($r)['tracks']['items'], 'id');
            foreach ([$private, $hidden, $bannedTrack] as $invisible) {
                self::assertNotContains($invisible->getId()->toRfc4122(), $ids, $q);
            }
            self::assertContains($public->getId()->toRfc4122(), $ids, $q.' (via similarity for the other artist name)');
        }

        // Hostile queries and wildcard look-alikes neither fail nor widen the match.
        foreach (['%%', '__', ':*', "a'b", 'a&b', '\\\\', '％％', '＿＿', '﹪﹪'] as $q) {
            $r = $this->api('GET', '/api/v1/search?type=tracks&q='.rawurlencode($q), $me);
            self::assertSame(200, $r->getStatusCode(), $q.': '.$r->getContent(false));
            self::assertSame([], $this->json($r)['tracks']['items'], $q.' must not match everything');
        }
        foreach (["ab\0cd", "ab\xff\xfe"] as $q) {
            self::assertContains($this->api('GET', '/api/v1/search?type=tracks&q='.rawurlencode($q), $me)->getStatusCode(), [400, 422], bin2hex($q));
        }
    }

    /** `reportListen` is limited to 120/min per user (bucket `listen`). */
    public function testListenReportsAreRateLimited(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $url = '/api/v1/tracks/'.$track->getId().'/listens';
        for ($i = 0; $i < 120; ++$i) {
            self::assertSame(200, $this->api('POST', $url, $bob, ['json' => ['listenedMs' => 1_000, 'source' => 'player']])->getStatusCode());
        }
        $this->assertProblem($this->api('POST', $url, $bob, ['json' => ['listenedMs' => 1_000, 'source' => 'player']]), 429, 'rate_limited');
    }

    /** Removing my like / follow on something that became invisible must stay possible. */
    public function testUnlikeStillWorksAfterTheTargetBecameInvisible(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $url = '/api/v1/tracks/'.$track->getId().'/like';
        self::assertSame(200, $this->api('PUT', $url, $bob)->getStatusCode());
        $this->em()->getConnection()->executeStatement("UPDATE track SET visibility = 'private' WHERE id = ?", [$track->getId()->toRfc4122()]);

        // Contract (W-26 F14): the like is hidden from my lists, and DELETE removes it without a
        // visibility check (404 only for an unknown target).
        self::assertSame([], $this->json($this->api('GET', '/api/v1/me/likes/tracks', $bob))['items']);
        $r = $this->api('DELETE', $url, $bob);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertSame(0, $this->json($r)['likeCount']);
        $this->assertProblem($this->api('DELETE', '/api/v1/tracks/'.self::UNKNOWN.'/like', $bob), 404, 'not_found');
        $this->em()->getConnection()->executeStatement("UPDATE track SET visibility = 'public' WHERE id = ?", [$track->getId()->toRfc4122()]);
        self::assertSame(0, $this->json($this->api('GET', '/api/v1/tracks/'.$track->getId(), $bob))['likeCount']);
    }
}
