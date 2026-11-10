<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Playlist;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\PostRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * W-47 (FB-18, ADR 012): every route that touches a track, for each audience × caller.
 *
 * Callers: O owner, F follower, C close friend, S stranger, X ex-follower (was a close friend,
 * then unfollowed), R follower removed from the close friends, M moderator. Anonymous callers get
 * 401 everywhere (an account is mandatory), checked once. An invisible track answers exactly
 * (status, headers, body) like an unknown id.
 */
final class TrackAudienceSecurityTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';
    private const array AUDIENCES = ['public', 'followers', 'close_friends', 'private'];

    /** Which audiences each caller is in (moderators aside). */
    private const array IN_AUDIENCE = [
        'O' => ['public', 'followers', 'close_friends', 'private'],
        'F' => ['public', 'followers'],
        'C' => ['public', 'followers', 'close_friends'],
        'S' => ['public'],
        'X' => ['public'],
        'R' => ['public', 'followers'],
        'M' => ['public'],
    ];

    /** @var array<string, User> */
    private array $users = [];
    /** @var array<string, Track> audience => track */
    private array $tracks = [];
    /** @var array<string, string> caller => own playlist id */
    private array $ownPlaylists = [];
    private Playlist $curated;

    public function testEachSingleTrackRouteAnswersPerAudienceAndCaller(): void
    {
        $this->world();
        foreach ($this->users as $who => $user) {
            $routes = [
                'getTrack' => fn (string $id): Response => $this->as($user, 'GET', '/api/v1/tracks/'.$id),
                'streamUrl' => fn (string $id): Response => $this->as($user, 'GET', '/api/v1/tracks/'.$id.'/stream-url'),
                'like' => fn (string $id): Response => $this->as($user, 'PUT', '/api/v1/tracks/'.$id.'/like'),
                'listen' => fn (string $id): Response => $this->as($user, 'POST', '/api/v1/tracks/'.$id.'/listens', ['source' => 'player', 'listenedMs' => 1000]),
                'playlistAdd' => fn (string $id): Response => $this->as($user, 'POST', '/api/v1/playlists/'.$this->ownPlaylists[$who].'/items', ['trackId' => $id]),
                'createPost' => fn (string $id): Response => $this->createPostAs($user, $id),
            ];
            foreach ($routes as $route => $call) {
                $reference = self::fingerprint($call(self::UNKNOWN));
                self::assertStringStartsWith('404', $reference, "$who $route unknown");
                foreach ($this->tracks as $audience => $track) {
                    // Clips: moderators count as anyone (CreatePostProcessor).
                    $sees = 'createPost' === $route ? \in_array($audience, self::IN_AUDIENCE[$who], true) : $this->sees($who, $audience);
                    $fp = self::fingerprint($call($track->getId()->toRfc4122()));
                    if ($sees) {
                        self::assertStringStartsNotWith('404', $fp, "$who $route $audience must be visible");
                    } else {
                        self::assertSame($reference, $fp, "$who $route $audience must answer like an unknown id");
                    }
                }
            }
            // Someone else's restricted track is visible but not clippable.
            if (\in_array($who, ['F', 'C', 'R'], true)) {
                self::assertSame('clips_not_allowed', self::problemCode($this->createPostAs($user, $this->id('followers'))), $who);
            }
        }
        self::assertSame(401, $this->raw('GET', '/api/v1/tracks/'.$this->id('public'))->getStatusCode(), 'anonymous');
    }

    public function testListsShowExactlyTheVisibleTracks(): void
    {
        $this->world();
        foreach ($this->users as $who => $user) {
            $seesAll = \in_array($who, ['O', 'M'], true);
            $expected = $seesAll ? self::AUDIENCES : self::IN_AUDIENCE[$who];
            self::assertSame($this->ids($expected), $this->listed($user, '/api/v1/users/owner/tracks', 'items'), "$who user tracks");
            self::assertSame($this->ids($expected), $this->listed($user, '/api/v1/artists/oartist/tracks', 'items'), "$who artist tracks");
            // Search is discovery: the audience rule only, no moderator bypass.
            self::assertSame($this->ids(self::IN_AUDIENCE[$who]), $this->listed($user, '/api/v1/search?type=tracks&q=zephyr', 'tracks'), "$who search");

            // Someone else's playlist holding all four: invisible items are absent, their cover unused.
            $expected = 'M' === $who ? self::AUDIENCES : self::IN_AUDIENCE[$who];
            self::assertSame($this->ids($expected), $this->listed($user, '/api/v1/playlists/'.$this->curated->getId().'/items', 'items'), "$who playlist items");
            $first = array_values(array_intersect(['private', 'close_friends', 'followers', 'public'], $expected))[0];
            $playlist = $this->decode($this->as($user, 'GET', '/api/v1/playlists/'.$this->curated->getId()));
            self::assertStringContainsString('covers/'.$first.'.webp', (string) $playlist['coverUrl'], "$who playlist cover");
        }
    }

    public function testUnfollowRevokesAccessAndRefollowDoesNotRestoreCloseFriendship(): void
    {
        $this->world();
        $conn = $this->em()->getConnection();
        $o = $this->users['O']->getId()->toRfc4122();
        self::assertSame([$this->users['C']->getId()->toRfc4122()], $conn->fetchFirstColumn('SELECT friend_id FROM close_friend WHERE owner_id = ?', [$o]), 'X lost by unfollow (FK), R removed');

        $x = $this->users['X'];
        // X liked the followers and close-friends tracks while it could: they leave its likes.
        self::assertSame($this->ids(['public']), $this->listed($x, '/api/v1/me/likes/tracks', 'items'));
        // The stream token issued before the unfollow dies on the next /stream; a public one lives.
        $gone = self::fingerprint($this->raw('GET', $this->streamPath['gone']));
        self::assertStringStartsWith('404', $gone);
        self::assertSame($gone, self::fingerprint($this->raw('GET', $this->streamPath['close_friends'])));
        self::assertSame($gone, self::fingerprint($this->raw('GET', $this->streamPath['followers'])));
        self::assertSame(200, $this->raw('GET', $this->streamPath['public'])->getStatusCode());

        // Re-follow: followers again, never close friend again.
        self::assertSame(200, $this->as($x, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        self::assertSame(200, $this->as($x, 'GET', '/api/v1/tracks/'.$this->id('followers'))->getStatusCode());
        self::assertSame(404, $this->as($x, 'GET', '/api/v1/tracks/'.$this->id('close_friends'))->getStatusCode());
        self::assertSame(1, (int) $conn->fetchOne('SELECT count(*) FROM close_friend WHERE owner_id = ?', [$o]));

        // A follower's track token dies when it unfollows.
        $f = $this->users['F'];
        $path = $this->streamPathFor($f, $this->id('followers'));
        self::assertSame(200, $this->raw('GET', $path)->getStatusCode());
        self::assertSame(200, $this->as($f, 'DELETE', '/api/v1/users/owner/follow')->getStatusCode());
        self::assertSame($gone, self::fingerprint($this->raw('GET', $path)));
    }

    public function testOwnersPostOnARestrictedTrackFollowsItsAudience(): void
    {
        $this->world();
        $conn = $this->em()->getConnection();
        [$o, $f, $s] = [$this->users['O'], $this->users['F'], $this->users['S']];
        $post = $this->post($o, $this->tracks['followers']);
        $id = $post->getId()->toRfc4122();
        $this->em()->clear();
        $setVisibility = fn (string $v) => $conn->executeStatement('UPDATE track SET visibility = ? WHERE id = ?', [$v, $this->id('followers')]);

        $reference = [];
        foreach (['', '/stream-url'] as $suffix) {
            $reference[$suffix] = self::fingerprint($this->as($s, 'GET', '/api/v1/posts/'.self::UNKNOWN.$suffix));
            self::assertStringStartsWith('404', $reference[$suffix]);
            self::assertSame($reference[$suffix], self::fingerprint($this->as($s, 'GET', '/api/v1/posts/'.$id.$suffix)), "S $suffix");
            foreach ([$o, $f] as $who) {
                self::assertSame(200, $this->as($who, 'GET', '/api/v1/posts/'.$id.$suffix)->getStatusCode(), $who->getUsername().$suffix);
            }
        }
        $clip = $this->clipPath($f, $id);
        self::assertSame(200, $this->raw('GET', $clip)->getStatusCode());
        // The embedded track hides its real audience from F.
        self::assertNull($this->decode($this->as($f, 'GET', '/api/v1/posts/'.$id))['track']['visibility']);

        $setVisibility('private');
        foreach (['', '/stream-url'] as $suffix) {
            self::assertSame($reference[$suffix], self::fingerprint($this->as($f, 'GET', '/api/v1/posts/'.$id.$suffix)), "F private $suffix");
        }
        self::assertSame(200, $this->as($o, 'GET', '/api/v1/posts/'.$id)->getStatusCode(), 'the author still sees it');
        self::assertSame(404, $this->raw('GET', $clip)->getStatusCode(), 'clip token issued before');

        $setVisibility('followers');
        self::assertSame(200, $this->as($f, 'GET', '/api/v1/posts/'.$id)->getStatusCode());
        self::assertSame(200, $this->raw('GET', $this->clipPath($f, $id))->getStatusCode());
    }

    public function testTrackVisibilityIsOnlyRevealedToOwnerAndModerators(): void
    {
        $this->world();
        foreach (self::AUDIENCES as $audience) {
            foreach ($this->users as $who => $user) {
                if (!$this->sees($who, $audience)) {
                    continue;
                }
                $expected = \in_array($who, ['O', 'M'], true) ? $audience : ('public' === $audience ? 'public' : null);
                $view = $this->decode($this->as($user, 'GET', '/api/v1/tracks/'.$this->id($audience)));
                self::assertSame($expected, $view['visibility'], "$who $audience");
            }
        }
    }

    // ------------------------------------------------------------- helpers ---

    /** @var array<string, string> audience => stream path issued to X before it unfollowed */
    private array $streamPath = [];

    private function world(): void
    {
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->users = [
            'O' => $this->createUser('owner'), 'F' => $this->createUser('fan'), 'C' => $this->createUser('close'),
            'S' => $this->createUser('stranger'), 'X' => $this->createUser('exfan'), 'R' => $this->createUser('removed'), 'M' => $mod,
        ];
        $this->em()->flush();
        $artist = $this->createArtist($this->users['O'], 'O Artist', 'oartist');
        foreach ([...self::AUDIENCES, 'gone'] as $audience) {
            $track = $this->createTrack($artist, 'Zephyr '.$audience, visibility: Visibility::tryFrom($audience) ?? Visibility::Public);
            $this->managed($track)->setCoverKey('covers/'.$audience.'.webp');
            $this->tracks[$audience] = $track;
        }
        $this->em()->flush();
        $conn = $this->em()->getConnection();
        $curator = $this->createUser('curator');
        $this->curated = $this->createPlaylist($curator, 'Curated');
        foreach (['private', 'close_friends', 'followers', 'public'] as $i => $audience) {
            $conn->executeStatement('INSERT INTO playlist_item (id, playlist_id, track_id, position, added_at) VALUES (gen_random_uuid(), ?, ?, ?, now())', [$this->curated->getId()->toRfc4122(), $this->id($audience), $i]);
        }
        $conn->executeStatement('UPDATE playlist SET item_count = 4 WHERE id = ?', [$this->curated->getId()->toRfc4122()]);
        foreach ($this->users as $who => $user) {
            $this->ownPlaylists[$who] = $this->createPlaylist($user, 'Own')->getId()->toRfc4122();
        }

        foreach (['F', 'C', 'X', 'R'] as $who) {
            self::assertSame(200, $this->as($this->users[$who], 'PUT', '/api/v1/users/owner/follow')->getStatusCode(), "$who follows");
        }
        foreach (['close', 'exfan', 'removed'] as $name) {
            self::assertSame(200, $this->as($this->users['O'], 'PUT', '/api/v1/me/close-friends/'.$name)->getStatusCode(), "$name added");
        }
        $x = $this->users['X'];
        foreach (['public', 'followers', 'close_friends', 'gone'] as $audience) {
            self::assertSame(200, $this->as($x, 'PUT', '/api/v1/tracks/'.$this->id($audience).'/like')->getStatusCode(), "X likes $audience");
            $this->streamPath[$audience] = $this->streamPathFor($x, $this->id($audience));
        }
        $conn->executeStatement('DELETE FROM track WHERE id = ?', [$this->id('gone')]);
        self::assertSame(200, $this->as($x, 'DELETE', '/api/v1/users/owner/follow')->getStatusCode(), 'X unfollows');
        self::assertSame(200, $this->as($this->users['O'], 'DELETE', '/api/v1/me/close-friends/removed')->getStatusCode(), 'R removed');
        unset($this->tracks['gone']);
        $this->em()->clear();
    }

    private function sees(string $who, string $audience): bool
    {
        return 'M' === $who || \in_array($audience, self::IN_AUDIENCE[$who], true);
    }

    private function id(string $audience): string
    {
        return $this->tracks[$audience]->getId()->toRfc4122();
    }

    /**
     * @param list<string> $audiences
     *
     * @return list<string> sorted track ids
     */
    private function ids(array $audiences): array
    {
        $ids = array_map(fn (string $a): string => $this->id($a), $audiences);
        sort($ids);

        return $ids;
    }

    /** @return list<string> sorted ids of the tracks listed under `$key` (items or tracks.items) */
    private function listed(User $user, string $uri, string $key): array
    {
        $r = $this->as($user, 'GET', $uri);
        self::assertSame(200, $r->getStatusCode(), $uri.' '.$r->getContent());
        $data = $this->decode($r);
        $items = 'tracks' === $key ? $data['tracks']['items'] : $data['items'];
        $ids = array_map(static fn (array $i): string => $i['trackId'] ?? $i['id'], $items);
        sort($ids);

        return $ids;
    }

    private function streamPathFor(User $user, string $trackId): string
    {
        $r = $this->as($user, 'GET', '/api/v1/tracks/'.$trackId.'/stream-url');
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return (string) parse_url((string) $this->decode($r)['url'], \PHP_URL_PATH);
    }

    private function clipPath(User $user, string $postId): string
    {
        $r = $this->as($user, 'GET', '/api/v1/posts/'.$postId.'/stream-url');
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return (string) parse_url((string) $this->decode($r)['url'], \PHP_URL_PATH);
    }

    /** @return array<string, mixed> */
    private function decode(Response $r): array
    {
        return json_decode((string) $r->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $body */
    private function as(User $user, string $method, string $uri, ?array $body = null): Response
    {
        return $this->raw($method, $uri, null === $body ? null : (string) json_encode($body), ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user)]);
    }

    private function createPostAs(User $user, string $trackId): Response
    {
        $browser = $this->client->getKernelBrowser();
        $browser->request('POST', '/api/v1/posts', ['trackId' => $trackId, 'clipStartMs' => '0', 'clipDurationMs' => '30000'], [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user),
            'CONTENT_TYPE' => 'multipart/form-data',
        ]);

        return $browser->getResponse();
    }

    private function post(User $author, Track $track): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));

        return $post;
    }

    /** Status, body and headers, minus what legitimately varies between two requests. */
    private static function fingerprint(Response $r): string
    {
        $headers = $r->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return $r->getStatusCode()."\n".json_encode($headers)."\n".$r->getContent();
    }
}
