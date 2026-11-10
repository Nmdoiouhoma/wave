<?php

declare(strict_types=1);

namespace App\Tests\Functional\Post;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/** W-32: post likes, comments, views and the following feed (DATA_MODEL §9–§11). */
final class PostInteractionTest extends WaveApiTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    public function testLikeIsIdempotentAndUnlikeFollowsF14(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $post = $this->makePost($alice, $track);
        $uri = '/api/v1/posts/'.$post->getId().'/like';

        self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', $uri, $bob)));
        self::assertSame(['liked' => true, 'likeCount' => 1], $this->json($this->api('PUT', $uri, $bob)));
        self::assertTrue($this->json($this->api('GET', '/api/v1/posts/'.$post->getId(), $bob))['likedByMe']);
        self::assertSame(['liked' => false, 'likeCount' => 0], $this->json($this->api('DELETE', $uri, $bob)));
        self::assertSame(['liked' => false, 'likeCount' => 0], $this->json($this->api('DELETE', $uri, $bob)));
        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.self::UNKNOWN.'/like', $bob), 404, 'not_found');
        $this->assertProblem($this->api('PUT', '/api/v1/posts/nope/like', $bob), 404, 'not_found');

        // The author can like a playable post of their own; a post still processing is not playable.
        self::assertSame(200, $this->api('PUT', $uri, $alice)->getStatusCode());
        $processing = $this->makePost($alice, $track, ready: false);
        $this->assertProblem($this->api('PUT', '/api/v1/posts/'.$processing->getId().'/like', $alice), 404, 'not_found');

        // F14: bob's own like goes even once the post is no longer visible to him.
        self::assertSame(200, $this->api('PUT', $uri, $bob)->getStatusCode());
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = ?", [$post->getId()]);
        self::assertSame(['liked' => false, 'likeCount' => 1], $this->json($this->api('DELETE', $uri, $bob)));
        $unknown = $this->api('DELETE', '/api/v1/posts/'.self::UNKNOWN.'/like', $bob);
        $hidden = $this->api('DELETE', $uri, $bob);
        $this->assertProblem($hidden, 404, 'not_found');
        self::assertSame($unknown->getContent(false), $hidden->getContent(false));
    }

    public function testCreateCommentValidatesAndCounts(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        $uri = '/api/v1/posts/'.$post->getId().'/comments';

        $r = $this->api('POST', $uri, $bob, ['json' => ['body' => "  \u{00A0}Nice <b>beat</b>\n "]]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
        $body = $this->json($r);
        self::assertSame('Nice <b>beat</b>', $body['body']);
        self::assertSame((string) $post->getId(), $body['postId']);
        self::assertSame('bob', $body['author']['username']);
        self::assertSame('visible', $body['moderation']);
        self::assertTrue($body['canDelete']);
        self::assertTrue(Uuid::isValid($body['id']));

        foreach (['', "   \n\t", "\u{00A0}\u{2003}", str_repeat('a', 501)] as $bad) {
            $this->assertProblem($this->api('POST', $uri, $bob, ['json' => ['body' => $bad]]), 422, 'validation_failed');
        }
        $this->assertProblem($this->api('POST', $uri, $bob, ['json' => ['body' => 12]]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $uri, $bob, ['json' => ['body' => 'x', 'moderation' => 'hidden']]), 422, 'validation_failed');
        $this->assertProblem($this->api('POST', $uri, $bob, ['json' => []]), 422, 'validation_failed');
        self::assertSame(201, $this->api('POST', $uri, $bob, ['json' => ['body' => '  '.str_repeat('é', 500).'  ']])->getStatusCode());
        $this->assertProblem($this->api('POST', '/api/v1/posts/'.self::UNKNOWN.'/comments', $bob, ['json' => ['body' => 'x']]), 404, 'not_found');
        $this->assertProblem($this->api('POST', $uri, $this->createUser('carol', verified: false), ['json' => ['body' => 'x']]), 403, 'email_not_verified');

        self::assertSame(2, $this->postCounter($post, 'comment_count'));
        self::assertSame(2, $this->json($this->api('GET', '/api/v1/posts/'.$post->getId(), $bob))['commentCount']);
    }

    public function testCommentCreationIsRateLimited(): void
    {
        $alice = $this->createUser('alice');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        foreach (['limiter.comment_minute_user' => 10, 'limiter.comment_day_user' => 200] as $limiter => $max) {
            $bob = $this->createUser('bob'.$max);
            /** @var RateLimiterFactoryInterface $factory */
            $factory = static::getContainer()->get($limiter);
            $factory->create($bob->getId()->toRfc4122())->consume($max);
            $r = $this->api('POST', '/api/v1/posts/'.$post->getId().'/comments', $bob, ['json' => ['body' => 'x']]);
            $this->assertProblem($r, 429, 'rate_limited');
        }
        self::assertSame(0, $this->postCounter($post, 'comment_count'));
    }

    public function testListCommentsNewestFirstWithModerationAndSuspension(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        $c1 = $this->comment($post, $bob, 'first', '-3 minutes');
        $c2 = $this->comment($post, $carol, 'second', '-2 minutes');
        $hidden = $this->comment($post, $carol, 'hidden', '-1 minute', 'hidden');
        $c4 = $this->comment($post, $bob, 'fourth', '-30 seconds');
        $dave = $this->createUser('dave');
        $this->comment($post, $dave, 'by suspended', '-10 seconds');
        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$dave->getId()]);
        $uri = '/api/v1/posts/'.$post->getId().'/comments';

        $page = $this->json($this->api('GET', $uri.'?limit=2', $bob));
        self::assertSame([$c4, $c2], array_column($page['items'], 'id'));
        self::assertSame([true, false], array_column($page['items'], 'canDelete'));
        self::assertNotNull($page['nextCursor']);
        $page2 = $this->json($this->api('GET', $uri.'?limit=2&cursor='.$page['nextCursor'], $bob));
        self::assertSame([$c1], array_column($page2['items'], 'id'));
        self::assertNull($page2['nextCursor']);

        self::assertSame([$c4, $hidden, $c2, $c1], array_column($this->json($this->api('GET', $uri, $carol))['items'], 'id'));
        $forMod = $this->json($this->api('GET', $uri, $mod))['items'];
        self::assertSame([$c4, $hidden, $c2, $c1], array_column($forMod, 'id'));
        self::assertSame([true, true, true, true], array_column($forMod, 'canDelete'));
        // The post author may delete every comment on it.
        self::assertSame([true, true, true], array_column($this->json($this->api('GET', $uri, $alice))['items'], 'canDelete'));

        $this->assertProblem($this->api('GET', $uri.'?cursor=bogus', $bob), 400, 'invalid_cursor');
        $this->assertProblem($this->api('GET', $uri.'?limit=51', $bob), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/posts/'.self::UNKNOWN.'/comments', $bob), 404, 'not_found');
    }

    public function testDeleteCommentRules(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        $byBob = $this->comment($post, $bob, 'bob');
        $byCarol = $this->comment($post, $carol, 'carol');
        $hidden = $this->comment($post, $carol, 'hidden', moderation: 'hidden');
        $this->sql("INSERT INTO report (id, reporter_id, target_type, target_id, reason, status, created_at) VALUES (gen_random_uuid(), ?, 'comment', ?, 'spam', 'open', now())", [$alice->getId(), $byBob]);

        $unknown = $this->api('DELETE', '/api/v1/comments/'.self::UNKNOWN, $bob);
        $this->assertProblem($unknown, 404, 'not_found');
        $this->assertProblem($this->api('DELETE', '/api/v1/comments/nope', $bob), 404, 'not_found');
        $r = $this->api('DELETE', '/api/v1/comments/'.$hidden, $bob);
        self::assertSame($unknown->getContent(false), $r->getContent(false), 'hidden comment of someone else');
        $this->assertProblem($this->api('DELETE', '/api/v1/comments/'.$byCarol, $bob), 403, 'forbidden');

        self::assertSame(204, $this->api('DELETE', '/api/v1/comments/'.$byBob, $bob)->getStatusCode());
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM report'));
        self::assertSame(1, $this->postCounter($post, 'comment_count'));
        $this->assertProblem($this->api('DELETE', '/api/v1/comments/'.$byBob, $bob), 404, 'not_found');
        // Hidden comments are not counted: deleting one leaves the counter.
        self::assertSame(204, $this->api('DELETE', '/api/v1/comments/'.$hidden, $carol)->getStatusCode());
        self::assertSame(1, $this->postCounter($post, 'comment_count'));
        self::assertSame(204, $this->api('DELETE', '/api/v1/comments/'.$byCarol, $alice)->getStatusCode(), 'post author');
        self::assertSame(0, $this->postCounter($post, 'comment_count'));
        $byCarol2 = $this->comment($post, $carol, 'again');
        self::assertSame(204, $this->api('DELETE', '/api/v1/comments/'.$byCarol2, $mod)->getStatusCode(), 'moderator');
    }

    public function testPostViewsUpsertClampAndCountFirstViewOnly(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $post = $this->makePost($alice, $track);
        $other = $this->makePost($alice, $track);
        $id = (string) $post->getId();

        $r = $this->api('POST', '/api/v1/post-views', $bob, ['json' => ['views' => [
            ['postId' => $id, 'watchedMs' => 5_000],
            ['postId' => strtoupper($id), 'watchedMs' => 12_000],
            ['postId' => (string) $other->getId(), 'watchedMs' => 600_000],
            ['postId' => self::UNKNOWN, 'watchedMs' => 1],
        ]]]);
        self::assertSame(204, $r->getStatusCode(), $r->getContent(false));
        self::assertSame(['watched_ms' => 12_000, 'completed' => false], $this->view($bob, $post));
        self::assertSame(['watched_ms' => 30_000, 'completed' => true], $this->view($bob, $other));
        self::assertSame(1, $this->postCounter($post, 'view_count'));

        self::assertSame(204, $this->api('POST', '/api/v1/post-views', $bob, ['json' => ['views' => [['postId' => $id, 'watchedMs' => 24_000]]]])->getStatusCode());
        self::assertSame(['watched_ms' => 24_000, 'completed' => true], $this->view($bob, $post));
        self::assertSame(204, $this->api('POST', '/api/v1/post-views', $bob, ['json' => ['views' => [['postId' => $id, 'watchedMs' => 1_000]]]])->getStatusCode());
        self::assertSame(['watched_ms' => 24_000, 'completed' => true], $this->view($bob, $post), 'max kept, completed sticky');
        self::assertSame(1, $this->postCounter($post, 'view_count'));

        // Own posts and posts still processing are ignored.
        $processing = $this->makePost($alice, $track, ready: false);
        self::assertSame(204, $this->api('POST', '/api/v1/post-views', $alice, ['json' => ['views' => [['postId' => $id, 'watchedMs' => 1_000]]]])->getStatusCode());
        self::assertSame(204, $this->api('POST', '/api/v1/post-views', $bob, ['json' => ['views' => [['postId' => (string) $processing->getId(), 'watchedMs' => 1_000]]]])->getStatusCode());
        self::assertNull($this->view($alice, $post));
        self::assertNull($this->view($bob, $processing));
        self::assertSame(1, $this->postCounter($post, 'view_count'));
    }

    public function testPostViewsValidation(): void
    {
        $bob = $this->createUser('bob');
        $item = ['postId' => self::UNKNOWN, 'watchedMs' => 1];
        foreach ([
            [],
            ['views' => []],
            ['views' => array_fill(0, 51, $item)],
            ['views' => [['postId' => self::UNKNOWN]]],
            ['views' => [$item + ['extra' => 1]]],
            ['views' => [['postId' => 'nope', 'watchedMs' => 1]]],
            ['views' => [['postId' => self::UNKNOWN, 'watchedMs' => -1]]],
            ['views' => [['postId' => self::UNKNOWN, 'watchedMs' => 600_001]]],
            ['views' => [['postId' => self::UNKNOWN, 'watchedMs' => '5']]],
            ['views' => [5]],
            ['views' => [$item], 'other' => true],
        ] as $i => $payload) {
            $this->assertProblem($this->api('POST', '/api/v1/post-views', $bob, ['json' => $payload]), 422, 'validation_failed');
        }
        self::assertSame(204, $this->api('POST', '/api/v1/post-views', $bob, ['json' => ['views' => array_fill(0, 50, $item)]])->getStatusCode());

        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.post_view_user');
        $eve = $this->createUser('eve');
        $factory->create($eve->getId()->toRfc4122())->consume(60);
        $this->assertProblem($this->api('POST', '/api/v1/post-views', $eve, ['json' => ['views' => [$item]]]), 429, 'rate_limited');
    }

    public function testFollowingFeed(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $dave = $this->createUser('dave');
        $me = $this->createUser('myself');
        $aTrack = $this->createTrack($this->createArtist($alice, 'A'));
        $cTrack = $this->createTrack($this->createArtist($carol, 'C'));
        $dTrack = $this->createTrack($this->createArtist($dave, 'D'));

        self::assertSame(['items' => [], 'nextCursor' => null], $this->json($this->api('GET', '/api/v1/feed/following', $me)));

        foreach (['alice', 'carol', 'dave'] as $name) {
            self::assertSame(200, $this->api('PUT', '/api/v1/users/'.$name.'/follow', $me)->getStatusCode());
        }
        $a1 = $this->makePost($alice, $aTrack, at: '-5 minutes');
        $c1 = $this->makePost($carol, $cTrack, at: '-4 minutes');
        $a2 = $this->makePost($alice, $aTrack, at: '-3 minutes');
        $this->makePost($alice, $aTrack, ready: false, at: '-2 minutes');
        $this->makePost($bob, $aTrack, at: '-1 minute'); // bob not followed
        $this->makePost($dave, $dTrack, at: '-1 minute');
        $this->sql('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$dave->getId()]);
        $hidden = $this->makePost($carol, $cTrack, at: '-30 seconds');
        $this->sql("UPDATE post SET moderation = 'hidden' WHERE id = ?", [$hidden->getId()]);

        $page = $this->json($this->api('GET', '/api/v1/feed/following?limit=2', $me));
        self::assertSame([(string) $a2->getId(), (string) $c1->getId()], array_column($page['items'], 'id'));
        self::assertNotNull($page['items'][0]['clip'], 'clip embedded');
        $page2 = $this->json($this->api('GET', '/api/v1/feed/following?limit=2&cursor='.$page['nextCursor'], $me));
        self::assertSame([(string) $a1->getId()], array_column($page2['items'], 'id'));
        self::assertNull($page2['nextCursor']);
        self::assertCount(3, $this->json($this->api('GET', '/api/v1/feed/following', $me))['items'], 'default limit 10');

        $this->assertProblem($this->api('GET', '/api/v1/feed/following?cursor='.substr((string) $page['nextCursor'], 0, -2).'xx', $me), 400, 'invalid_cursor');
        foreach (['0', '21', 'x'] as $limit) {
            $this->assertProblem($this->api('GET', '/api/v1/feed/following?limit='.$limit, $me), 400, 'malformed_request');
        }
        self::assertSame(401, $this->api('GET', '/api/v1/feed/following')->getStatusCode());
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

    private function comment(Post $post, User $author, string $body, string $at = 'now', string $moderation = 'visible'): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->sql(
            'INSERT INTO comment (id, post_id, author_id, body, moderation, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $post->getId(), $author->getId(), $body, $moderation, new \DateTimeImmutable($at)->format('Y-m-d H:i:s.uP')],
        );
        if ('visible' === $moderation) {
            $this->sql('UPDATE post SET comment_count = comment_count + 1 WHERE id = ?', [$post->getId()]);
        }

        return $id;
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params): void
    {
        $this->em()->getConnection()->executeStatement($sql, array_map(static fn (mixed $p): mixed => $p instanceof Uuid ? $p->toRfc4122() : $p, $params));
        $this->em()->clear(); // the kernel shares this entity manager: drop stale copies
    }

    private function postCounter(Post $post, string $column): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT {$column} FROM post WHERE id = ?", [$post->getId()->toRfc4122()]);
    }

    /** @return array{watched_ms: int, completed: bool}|null */
    private function view(User $user, Post $post): ?array
    {
        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT watched_ms, completed FROM post_view WHERE user_id = ? AND post_id = ?',
            [$user->getId()->toRfc4122(), $post->getId()->toRfc4122()],
        );

        return false === $row ? null : ['watched_ms' => (int) $row['watched_ms'], 'completed' => (bool) $row['completed']];
    }
}
