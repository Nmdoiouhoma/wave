<?php

declare(strict_types=1);

namespace App\Tests\Functional\Social;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Pagination\CursorCodec;
use App\Repository\PostRepository;
use App\Repository\RepostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/** W-80 (ADR 015): reposts — endpoints, error codes, feed/following, live filters, §13b. */
final class RepostTest extends WaveApiTestCase
{
    public function testRepostIsIdempotentAndReflectedEverywhere(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        $url = '/api/v1/posts/'.$post->getId().'/repost';

        self::assertSame(['reposted' => true, 'repostCount' => 1], $this->json($this->api('PUT', $url, $bob)));
        self::assertSame(['reposted' => true, 'repostCount' => 1], $this->json($this->api('PUT', $url, $bob)), 'idempotent');

        $view = $this->json($this->api('GET', '/api/v1/posts/'.$post->getId(), $bob));
        self::assertTrue($view['repostedByMe']);
        self::assertSame(1, $view['repostCount']);
        self::assertTrue($view['canRepost']);
        self::assertNull($view['repostedBy'], 'no attribution outside the repost lists');
        self::assertNull($view['repostedAt']);

        $own = $this->json($this->api('GET', '/api/v1/posts/'.$post->getId(), $alice));
        self::assertFalse($own['canRepost'], 'cannot repost own post');
        self::assertFalse($own['repostedByMe']);

        self::assertSame(['reposted' => true, 'repostCount' => 2], $this->json($this->api('PUT', $url, $carol)));
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne(
            "SELECT count(*) FROM notification WHERE recipient_id = ? AND type = 'repost'",
            [$alice->getId()->toRfc4122()],
        ), 'author notified once, aggregated like post_like');

        self::assertSame(['reposted' => false, 'repostCount' => 1], $this->json($this->api('DELETE', $url, $bob)));
        self::assertSame(['reposted' => false, 'repostCount' => 1], $this->json($this->api('DELETE', $url, $bob)), 'idempotent');
        self::assertFalse($this->json($this->api('GET', '/api/v1/posts/'.$post->getId(), $bob))['repostedByMe']);
    }

    public function testRepostErrorCodes(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $public = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));

        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.$public->getId().'/repost', $alice), 422, 'cannot_repost_own');

        $followers = $this->makePost($alice, $this->createTrack($this->createArtist($alice, 'F', 'f'), 'F', visibility: Visibility::Followers));
        $this->api('PUT', '/api/v1/users/alice/follow', $bob);
        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.$followers->getId().'/repost', $bob), 422, 'repost_not_allowed'); // visible but restricted audience

        $private = $this->makePost($alice, $this->createTrack($this->createArtist($alice, 'P', 'p'), 'P', visibility: Visibility::Private));
        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.$private->getId().'/repost', $bob), 404, 'not_found'); // not playable → 404, no oracle

        $dave = $this->createUser('dave', verified: false);
        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.$public->getId().'/repost', $dave), 403, 'email_not_verified');
    }

    public function testRepostIsRateLimited(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));

        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.repost_hour_user');
        $factory->create($bob->getId()->toRfc4122())->consume(30);
        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.$post->getId().'/repost', $bob), 429, 'rate_limited');
        self::assertSame(0, $this->postCounter($post, 'repost_count'), 'rejected request never bumps the counter');
    }

    public function testRepostHydrationHasNoNPlusOne(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        for ($i = 0; $i < 20; ++$i) {
            $post = $this->makePost($alice, $track, at: '-'.$i.' minutes');
            if (0 === $i % 3) {
                $this->api('PUT', '/api/v1/posts/'.$post->getId().'/repost', $bob);
            }
        }

        $two = $this->countQueries('GET', '/api/v1/users/alice/posts?limit=2', $bob);
        $twenty = $this->countQueries('GET', '/api/v1/users/alice/posts?limit=20', $bob);
        self::assertSame($two, $twenty, 'repostedByMe / canRepost must not grow with the page size');
    }

    public function testFollowingFeedFoldsRepostsAndLiveFilters(): void
    {
        $alice = $this->createUser('alice'); // not followed by me
        $carol = $this->createUser('carol');
        $me = $this->createUser('myself');
        $aTrack = $this->createTrack($this->createArtist($alice, 'A'));
        $a1 = $this->makePost($alice, $aTrack, at: '-5 minutes');
        $c1 = $this->makePost($carol, $this->createTrack($this->createArtist($carol, 'C')), at: '-4 minutes');
        $this->api('PUT', '/api/v1/users/carol/follow', $me);
        $this->api('PUT', '/api/v1/posts/'.$a1->getId().'/repost', $carol); // latest activity on a1

        $page = $this->json($this->api('GET', '/api/v1/feed/following', $me));
        self::assertSame([(string) $a1->getId(), (string) $c1->getId()], array_column($page['items'], 'id'));
        self::assertSame('carol', $page['items'][0]['repostedBy']['username']);
        self::assertNotNull($page['items'][0]['repostedAt']);
        self::assertSame(1, $page['items'][0]['repostCount']);
        self::assertNull($page['items'][1]['repostedBy'], 'authored post: no attribution');

        // Phase-3 (plain keyset) cursors are rejected.
        $old = static::getContainer()->get(CursorCodec::class)->encode(['2026-01-01T00:00:00.000000Z', Uuid::v7()->toRfc4122()]);
        $this->assertProblem($this->api('GET', '/api/v1/feed/following?cursor='.$old, $me), 400, 'invalid_cursor');

        // Reposter suspended → a1 gone (alice not followed); counts unchanged.
        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$carol->getId()]);
        self::assertSame([], $this->json($this->api('GET', '/api/v1/feed/following', $me))['items']);
        self::assertSame(1, $this->postCounter($a1, 'repost_count'));
        $this->sql('UPDATE app_user SET suspended_at = NULL WHERE id = ?', [$carol->getId()]);

        // Post hidden → gone from the repost path (c1 remains via authorship).
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = ?", [$a1->getId()]);
        self::assertSame([(string) $c1->getId()], array_column($this->json($this->api('GET', '/api/v1/feed/following', $me))['items'], 'id'));
        self::assertSame(1, $this->postCounter($a1, 'repost_count'));
        $this->sql("UPDATE post SET moderation = 'visible' WHERE id = ?", [$a1->getId()]);

        // Track turned followers-only → a1 loses its public audience for the non-follower me.
        $this->sql("UPDATE track SET visibility = 'followers' WHERE id = ?", [$aTrack->getId()]);
        self::assertSame([(string) $c1->getId()], array_column($this->json($this->api('GET', '/api/v1/feed/following', $me))['items'], 'id'));
        self::assertSame(1, $this->postCounter($a1, 'repost_count'), 'live filter never rewrites counters');
    }

    public function testUserRepostsListAndLiveFilter(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $track = $this->createTrack($this->createArtist($bob, 'B'));
        $p1 = $this->makePost($bob, $track, at: '-2 minutes');
        $p2 = $this->makePost($bob, $track, at: '-1 minute');
        $this->api('PUT', '/api/v1/posts/'.$p1->getId().'/repost', $alice);
        $this->api('PUT', '/api/v1/posts/'.$p2->getId().'/repost', $alice); // later repost

        $list = $this->json($this->api('GET', '/api/v1/users/alice/reposts', $carol));
        self::assertSame([(string) $p2->getId(), (string) $p1->getId()], array_column($list['items'], 'id'), 'newest repost first');
        self::assertSame('alice', $list['items'][0]['repostedBy']['username']);
        self::assertNotNull($list['items'][0]['repostedAt']);

        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = ?", [$p1->getId()]);
        self::assertSame([(string) $p2->getId()], array_column($this->json($this->api('GET', '/api/v1/users/alice/reposts', $carol))['items'], 'id'));
        self::assertSame(1, $this->postCounter($p1, 'repost_count'), 'live filter, counter untouched');

        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$alice->getId()]);
        $this->assertProblem($this->api('GET', '/api/v1/users/alice/reposts', $carol), 404, 'not_found');
    }

    public function testReposterErasureDecrementsAndPostDeletionCascades(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        $this->api('PUT', '/api/v1/posts/'.$post->getId().'/repost', $bob);
        self::assertSame(1, $this->postCounter($post, 'repost_count'));

        // §13b: erasing a reposter decrements the counter and drops the rows.
        static::getContainer()->get(RepostRepository::class)->purgeByUser($bob->getId());
        self::assertSame(0, $this->postCounter($post, 'repost_count'));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM repost WHERE user_id = ?', [$bob->getId()->toRfc4122()]));

        // Post deletion cascades to its reposts.
        $this->api('PUT', '/api/v1/posts/'.$post->getId().'/repost', $carol);
        $this->em()->getConnection()->executeStatement('DELETE FROM post WHERE id = ?', [$post->getId()->toRfc4122()]);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM repost WHERE post_id = ?', [$post->getId()->toRfc4122()]));
    }

    private function makePost(User $author, Track $track, bool $ready = true, string $at = 'now'): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        $this->sql('UPDATE post SET created_at = ? WHERE id = ?', [new \DateTimeImmutable($at)->format('Y-m-d H:i:s.uP'), $post->getId()]);
        if ($ready) {
            self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
            $this->em()->clear();
        }

        return $post;
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params): void
    {
        $this->em()->getConnection()->executeStatement($sql, array_map(static fn (mixed $p): mixed => $p instanceof Uuid ? $p->toRfc4122() : $p, $params));
        $this->em()->clear();
    }

    private function postCounter(Post $post, string $column): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT {$column} FROM post WHERE id = ?", [$post->getId()->toRfc4122()]);
    }
}
