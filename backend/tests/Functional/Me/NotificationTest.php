<?php

declare(strict_types=1);

namespace App\Tests\Functional\Me;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\NotificationRepository;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/** W-45: in-app notifications (FB-16, DATA_MODEL §8b). */
final class NotificationTest extends WaveApiTestCase
{
    private const string LIST = '/api/v1/me/notifications';
    private const string COUNT = '/api/v1/me/notifications/unread-count';
    private const string READ = '/api/v1/me/notifications/read';

    public function testEventsAreWrittenOnlyWhenARowIsInserted(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice), 'Night');
        $post = $this->makePost($alice, $track);

        // Like twice → one actor; own like and own comment → nothing.
        self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$track->getId().'/like', $bob)->getStatusCode());
        self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$track->getId().'/like', $bob)->getStatusCode());
        self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$track->getId().'/like', $alice)->getStatusCode());
        self::assertSame(200, $this->api('PUT', '/api/v1/posts/'.$post->getId().'/like', $alice)->getStatusCode());
        self::assertSame(201, $this->api('POST', '/api/v1/posts/'.$post->getId().'/comments', $alice, ['json' => ['body' => 'mine']])->getStatusCode());
        self::assertSame(200, $this->api('PUT', '/api/v1/users/alice/follow', $bob)->getStatusCode());
        self::assertSame(201, $this->api('POST', '/api/v1/posts/'.$post->getId().'/comments', $bob, ['json' => ['body' => 'nice']])->getStatusCode());

        $items = $this->json($this->api('GET', self::LIST, $alice))['items'];
        self::assertSame(['comment', 'follow', 'track_like'], array_column($items, 'type'));
        self::assertSame(3, $this->rows('notification'));
        [$comment, $follow, $like] = $items;
        self::assertNull($follow['target']);
        self::assertSame(['bob'], array_column($follow['actors'], 'username'));
        self::assertSame(['type' => 'track', 'id' => (string) $track->getId(), 'label' => 'Night', 'imageUrl' => null], $like['target']);
        self::assertSame(1, $like['actorCount']);
        self::assertFalse($like['read']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{6}\+00:00$/', $like['updatedAt']);
        self::assertSame('post', $comment['target']['type']);
        self::assertSame('Night', $comment['target']['label']); // no caption → track title
        self::assertSame(['unreadCount' => 3], $this->json($this->api('GET', self::COUNT, $alice)));
        self::assertSame(['items' => [], 'nextCursor' => null], $this->json($this->api('GET', self::LIST, $bob)));
    }

    public function testAggregationReadAndNewItem(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $uri = '/api/v1/tracks/'.$track->getId().'/like';
        foreach (['fan1', 'fan2', 'fan3'] as $name) {
            self::assertSame(200, $this->api('PUT', $uri, $this->createUser($name))->getStatusCode());
        }
        $items = $this->json($this->api('GET', self::LIST, $alice))['items'];
        self::assertCount(1, $items);
        self::assertSame(3, $items[0]['actorCount']);
        self::assertSame(['fan3', 'fan2', 'fan1'], array_column($items[0]['actors'], 'username'));

        $r = $this->api('POST', self::READ, $alice, ['json' => ['upTo' => $items[0]['updatedAt']]]);
        self::assertSame(204, $r->getStatusCode(), $r->getContent(false));
        self::assertSame(['unreadCount' => 0], $this->json($this->api('GET', self::COUNT, $alice)));
        self::assertTrue($this->json($this->api('GET', self::LIST, $alice))['items'][0]['read']);

        self::assertSame(200, $this->api('PUT', $uri, $this->createUser('fan4'))->getStatusCode());
        $items = $this->json($this->api('GET', self::LIST, $alice))['items'];
        self::assertCount(2, $items);
        self::assertFalse($items[0]['read']);
        self::assertSame(['fan4'], array_column($items[0]['actors'], 'username'));
        self::assertSame(1, $items[0]['actorCount']);
        self::assertTrue($items[1]['read']);
        self::assertSame(3, $items[1]['actorCount']);

        // Two follows of the same user while unread: one `follow` row (NULLS NOT DISTINCT).
        $this->api('PUT', '/api/v1/users/alice/follow', $this->createUser('fol1'));
        $this->api('PUT', '/api/v1/users/alice/follow', $this->createUser('fol2'));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT count(*) FROM notification WHERE type = 'follow'"));
    }

    public function testUndoAndCleanup(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $track = $this->createTrack($this->createArtist($alice));
        $playlist = $this->createPlaylist($alice);
        $post = $this->makePost($alice, $track);

        // Unlike / unfollow remove the actor; the item goes when empty.
        $uri = '/api/v1/tracks/'.$track->getId().'/like';
        $this->api('PUT', $uri, $bob);
        $this->api('PUT', $uri, $carol);
        $this->api('DELETE', $uri, $bob);
        $items = $this->json($this->api('GET', self::LIST, $alice))['items'];
        self::assertSame(['carol'], array_column($items[0]['actors'], 'username'));
        $this->api('DELETE', $uri, $carol);
        self::assertSame(0, $this->rows('notification'));
        $this->api('PUT', '/api/v1/users/alice/follow', $bob);
        $this->api('DELETE', '/api/v1/users/alice/follow', $bob);
        self::assertSame(0, $this->rows('notification'));

        // Like/unlike loop: at most one item per read.
        for ($i = 0; $i < 3; ++$i) {
            $this->api('PUT', $uri, $bob);
            $this->api('DELETE', $uri, $bob);
            $this->api('PUT', $uri, $bob);
        }
        self::assertSame(1, $this->rows('notification'));

        // Deleting the target removes its items (playlist, post, track with its posts).
        $this->api('PUT', '/api/v1/playlists/'.$playlist->getId().'/like', $bob);
        $this->api('PUT', '/api/v1/posts/'.$post->getId().'/like', $bob);
        $this->api('POST', '/api/v1/posts/'.$post->getId().'/comments', $bob, ['json' => ['body' => 'x']]);
        self::assertSame(4, $this->rows('notification'));
        self::assertSame(204, $this->api('DELETE', '/api/v1/playlists/'.$playlist->getId(), $alice)->getStatusCode());
        self::assertSame(3, $this->rows('notification'));
        self::assertSame(204, $this->api('DELETE', '/api/v1/tracks/'.$track->getId(), $alice)->getStatusCode());
        self::assertSame(0, $this->rows('notification'));

        $other = $this->makePost($alice, $this->createTrack($this->createArtist($alice, 'Two', 'two')));
        $this->api('PUT', '/api/v1/posts/'.$other->getId().'/like', $bob);
        self::assertSame(204, $this->api('DELETE', '/api/v1/posts/'.$other->getId(), $alice)->getStatusCode());
        self::assertSame(0, $this->rows('notification'));

        // Deleted only actor (DATA_MODEL §13b): omitted at read time, then purged by step 4.
        $this->api('PUT', '/api/v1/users/alice/follow', $carol);
        $this->db()->executeStatement('DELETE FROM app_user WHERE id = ?', [$carol->getId()->toRfc4122()]);
        self::assertSame([], $this->json($this->api('GET', self::LIST, $alice))['items']);
        self::assertSame(['unreadCount' => 0], $this->json($this->api('GET', self::COUNT, $alice)));
        self::assertSame(1, static::getContainer()->get(NotificationRepository::class)->deleteOrphans());
        self::assertSame(0, $this->rows('notification'));
    }

    public function testReadFiltersSuspendedActorsAndInvisibleTargets(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $eve = $this->createUser('eve');
        $mine = $this->createTrack($this->createArtist($alice), 'Mine');
        $uri = '/api/v1/tracks/'.$mine->getId().'/like';
        $this->api('PUT', $uri, $bob);
        $this->api('PUT', $uri, $eve);
        $this->api('PUT', '/api/v1/users/alice/follow', $eve);
        $this->db()->executeStatement('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$eve->getId()->toRfc4122()]);

        $items = $this->json($this->api('GET', self::LIST, $alice))['items'];
        self::assertSame(['track_like'], array_column($items, 'type')); // follow item: only a suspended actor
        self::assertSame(['bob'], array_column($items[0]['actors'], 'username'));
        self::assertSame(1, $items[0]['actorCount']);
        self::assertSame(['unreadCount' => 1], $this->json($this->api('GET', self::COUNT, $alice)));

        // Post on bob's clippable track: absent for its author once bob's track turns private.
        $bobsTrack = $this->createTrack($this->createArtist($bob, 'Bob', 'bob-band'), 'Bobs');
        $clip = $this->makePost($alice, $bobsTrack);
        $this->api('PUT', '/api/v1/posts/'.$clip->getId().'/like', $bob);
        self::assertCount(2, $this->json($this->api('GET', self::LIST, $alice))['items']);
        $this->db()->executeStatement("UPDATE track SET visibility = 'private' WHERE id = ?", [$bobsTrack->getId()->toRfc4122()]);
        self::assertCount(1, $this->json($this->api('GET', self::LIST, $alice))['items']);
        self::assertSame(['unreadCount' => 1], $this->json($this->api('GET', self::COUNT, $alice)));

        // Own hidden track and own close_friends/followers track + post stay listed for the owner.
        $this->db()->executeStatement("UPDATE track SET moderation = 'hidden' WHERE id = ?", [$mine->getId()->toRfc4122()]);
        $cf = $this->createTrack($this->createArtist($alice, 'Cf', 'cf-band'), 'Close', visibility: Visibility::CloseFriends);
        $cfPost = $this->makePost($alice, $cf);
        $this->api('PUT', '/api/v1/users/alice/follow', $bob);
        self::assertSame(200, $this->api('PUT', '/api/v1/me/close-friends/bob', $alice)->getStatusCode());
        self::assertSame(200, $this->api('PUT', '/api/v1/posts/'.$cfPost->getId().'/like', $bob)->getStatusCode());
        $this->api('PUT', '/api/v1/tracks/'.$cf->getId().'/like', $bob);
        // Bob leaves the list: the items stay for alice (she is always in her own audience).
        $this->api('DELETE', '/api/v1/me/close-friends/bob', $alice);
        $types = array_column($this->json($this->api('GET', self::LIST, $alice))['items'], 'type');
        self::assertSame(['track_like', 'post_like', 'follow', 'track_like'], $types);
        self::assertSame(['unreadCount' => 4], $this->json($this->api('GET', self::COUNT, $alice)));
    }

    public function testEndpointsContract(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $tracks = [];
        for ($i = 0; $i < 3; ++$i) {
            $tracks[] = $t = $this->createTrack($this->createArtist($alice, 'A'.$i, 'a'.$i), 'T'.$i);
            $this->api('PUT', '/api/v1/tracks/'.$t->getId().'/like', $bob);
        }

        // Keyset pages, tampered cursor and bad limit → 400.
        $first = $this->json($this->api('GET', self::LIST.'?limit=2', $alice));
        self::assertSame(['T2', 'T1'], array_column(array_column($first['items'], 'target'), 'label'));
        self::assertNotNull($first['nextCursor']);
        $second = $this->json($this->api('GET', self::LIST.'?limit=2&cursor='.urlencode((string) $first['nextCursor']), $alice));
        self::assertSame(['T0'], array_column(array_column($second['items'], 'target'), 'label'));
        self::assertNull($second['nextCursor']);
        $this->assertProblem($this->api('GET', self::LIST.'?cursor=abc', $alice), 400, 'invalid_cursor');
        $this->assertProblem($this->api('GET', self::LIST.'?cursor='.urlencode(substr((string) $first['nextCursor'], 0, -2).'xx'), $alice), 400, 'invalid_cursor');
        $this->assertProblem($this->api('GET', self::LIST.'?limit=51', $alice), 400, 'malformed_request');

        // `read` marks only the caller's rows with wire updatedAt ≤ upTo.
        $carol = $this->createUser('carol');
        $this->api('PUT', '/api/v1/users/bob/follow', $carol);
        $this->db()->executeStatement(
            "UPDATE notification SET updated_at = '2026-01-01 10:00:00.700+00' WHERE track_id = ?",
            [$tracks[0]->getId()->toRfc4122()],
        );
        // Exact comparison at the microsecond: 1 µs before the wire updatedAt marks nothing, the exact
        // value marks it; a date without fraction or with milliseconds is still accepted.
        self::assertSame(204, $this->api('POST', self::READ, $alice, ['json' => ['upTo' => '2026-01-01T10:00:00Z']])->getStatusCode());
        self::assertSame(204, $this->api('POST', self::READ, $alice, ['json' => ['upTo' => '2026-01-01T10:00:00.699999+00:00']])->getStatusCode());
        self::assertSame(['unreadCount' => 3], $this->json($this->api('GET', self::COUNT, $alice)));
        self::assertSame(204, $this->api('POST', self::READ, $alice, ['json' => ['upTo' => '2026-01-01T10:00:00.700000+00:00']])->getStatusCode());
        self::assertSame(['unreadCount' => 2], $this->json($this->api('GET', self::COUNT, $alice)));
        self::assertSame(['unreadCount' => 1], $this->json($this->api('GET', self::COUNT, $bob)));
        self::assertSame(204, $this->api('POST', self::READ, $alice, ['json' => ['upTo' => '2026-01-01T10:00:00.700Z']])->getStatusCode());
        foreach ([['upTo' => '2026-01-01T10:00:00Z', 'id' => 'x'], [], ['upTo' => 'yesterday'], ['upTo' => '2026-02-30T10:00:00Z'], ['upTo' => 12]] as $bad) {
            $this->assertProblem($this->api('POST', self::READ, $alice, ['json' => $bad]), 422, 'validation_failed');
        }

        // Unread count capped at 100 (105 unread items on distinct playlists).
        for ($i = 0; $i < 105; ++$i) {
            $this->db()->executeStatement(
                "INSERT INTO playlist (id, owner_id, name, created_at, updated_at) VALUES (?, ?, 'p', now(), now())",
                [$pid = Uuid::v7()->toRfc4122(), $carol->getId()->toRfc4122()],
            );
            $this->db()->executeStatement(
                "INSERT INTO notification (id, recipient_id, type, playlist_id, created_at, updated_at) VALUES (?, ?, 'playlist_like', ?, now(), now())",
                [$nid = Uuid::v7()->toRfc4122(), $carol->getId()->toRfc4122(), $pid],
            );
            $this->db()->executeStatement('INSERT INTO notification_actor (notification_id, actor_id, created_at) VALUES (?, ?, now())', [$nid, $bob->getId()->toRfc4122()]);
        }
        self::assertSame(['unreadCount' => 100], $this->json($this->api('GET', self::COUNT, $carol)));

        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.notifications_user');
        $eve = $this->createUser('eve');
        $factory->create($eve->getId()->toRfc4122())->consume(120);
        $this->assertProblem($this->api('GET', self::COUNT, $eve), 429, 'rate_limited');
        $this->assertProblem($this->api('GET', self::COUNT), 401, 'unauthenticated');
    }

    private function makePost(User $author, Track $track): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        $this->em()->clear();

        return $post;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        $this->em()->clear();

        return $this->em()->getConnection();
    }

    private function rows(string $table): int
    {
        return (int) $this->db()->fetchOne("SELECT count(*) FROM {$table}");
    }
}
