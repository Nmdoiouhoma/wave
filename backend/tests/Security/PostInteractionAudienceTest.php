<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\PostRepository;
use Symfony\Component\HttpFoundation\Response;

/**
 * W-32: every post interaction (like, comment, views, feed listen, following feed) follows the
 * viewer-dependent "playable" rule of TrackAudience (DATA_MODEL §9, §11, FB-18, SECURITY T17).
 * O owns restricted tracks, F follows O, C is O's close friend, S is a stranger.
 */
final class PostInteractionAudienceTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    private User $o;
    private User $f;
    private User $c;
    private User $s;
    private Track $followers;
    private Track $closeFriends;

    protected function setUp(): void
    {
        parent::setUp();
        $this->o = $this->createUser('owner');
        $this->f = $this->createUser('follower');
        $this->c = $this->createUser('closefriend');
        $this->s = $this->createUser('stranger');
        $artist = $this->createArtist($this->o);
        $this->followers = $this->createTrack($artist, 'F', true, Visibility::Followers, false);
        $this->closeFriends = $this->createTrack($artist, 'C', true, Visibility::CloseFriends, false);
        foreach ([$this->f, $this->c] as $who) {
            self::assertLessThan(300, $this->as($who, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        }
        self::assertLessThan(300, $this->as($this->o, 'PUT', '/api/v1/me/close-friends/closefriend')->getStatusCode());
    }

    /** The ticket's test: a follower's feed listen counts, a stranger's does not, nor after unfollowing. */
    public function testFeedListenNeedsAPostPlayableForTheCaller(): void
    {
        $post = $this->post($this->o, $this->followers);
        $trackId = $this->followers->getId()->toRfc4122();
        $uri = '/api/v1/tracks/'.$trackId.'/listens';
        $feed = ['listenedMs' => 15_000, 'source' => 'feed', 'postId' => $post->getId()->toRfc4122()];

        $this->clip($this->f, $post);
        $this->ageGrants();
        $r = $this->as($this->f, 'POST', $uri, $feed);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame(['counted' => true, 'playCount' => 1], $this->decode($r));
        self::assertSame($post->getId()->toRfc4122(), $this->db('SELECT post_id FROM listen WHERE user_id = ?', $this->f));

        self::assertSame(404, $this->as($this->s, 'GET', '/api/v1/posts/'.$post->getId().'/stream-url')->getStatusCode());
        self::assertSame(404, $this->as($this->s, 'POST', $uri, ['listenedMs' => 15_000, 'source' => 'player'])->getStatusCode());
        self::assertSame('validation_failed', self::problemCode($this->as($this->s, 'POST', $uri, $feed)));
        self::assertSame(422, $this->as($this->s, 'POST', $uri, $feed)->getStatusCode());

        $this->clip($this->f, $post);
        $this->ageGrants();
        self::assertLessThan(300, $this->as($this->f, 'DELETE', '/api/v1/users/owner/follow')->getStatusCode());
        $r = $this->as($this->f, 'POST', $uri, $feed);
        self::assertSame(422, $r->getStatusCode(), (string) $r->getContent());
        self::assertSame('1', (string) $this->db('SELECT play_count FROM track WHERE id = ?', $this->followers));
    }

    public function testFeedListenRejectsAnyOtherPost(): void
    {
        $post = $this->post($this->o, $this->followers);
        $other = $this->post($this->o, $this->closeFriends);
        $uri = '/api/v1/tracks/'.$this->followers->getId().'/listens';
        foreach ([
            ['listenedMs' => 15_000, 'source' => 'feed'],
            ['listenedMs' => 15_000, 'source' => 'feed', 'postId' => self::UNKNOWN],
            ['listenedMs' => 15_000, 'source' => 'feed', 'postId' => 'nope'],
            ['listenedMs' => 15_000, 'source' => 'feed', 'postId' => $other->getId()->toRfc4122()],
            ['listenedMs' => 15_000, 'source' => 'player', 'postId' => $post->getId()->toRfc4122()],
        ] as $i => $body) {
            $r = $this->as($this->c, 'POST', $uri, $body);
            self::assertSame(422, $r->getStatusCode(), $i.' '.$r->getContent());
        }
        $r = $this->as($this->c, 'POST', '/api/v1/tracks/'.self::UNKNOWN.'/listens', ['listenedMs' => 15_000, 'source' => 'feed', 'postId' => $post->getId()->toRfc4122()]);
        self::assertSame(422, $r->getStatusCode(), 'post of another track');
    }

    public function testInteractionsOnANonPlayablePostLookUnknown(): void
    {
        $post = $this->post($this->o, $this->closeFriends);
        $id = $post->getId()->toRfc4122();
        $comment = $this->as($this->c, 'POST', '/api/v1/posts/'.$id.'/comments', ['body' => 'hi']);
        self::assertSame(201, $comment->getStatusCode(), (string) $comment->getContent());
        $commentId = $this->decode($comment)['id'];
        self::assertSame(200, $this->as($this->c, 'PUT', '/api/v1/posts/'.$id.'/like')->getStatusCode());

        foreach ([
            ['PUT', '/like', null],
            ['DELETE', '/like', null],
            ['GET', '/comments', null],
            ['POST', '/comments', ['body' => 'hi']],
        ] as [$method, $suffix, $body]) {
            $reference = self::fingerprint($this->as($this->f, $method, '/api/v1/posts/'.self::UNKNOWN.$suffix, $body));
            self::assertStringStartsWith('404', $reference);
            foreach (['follower' => $this->f, 'stranger' => $this->s] as $name => $who) {
                self::assertSame($reference, self::fingerprint($this->as($who, $method, '/api/v1/posts/'.$id.$suffix, $body)), "$name $method $suffix");
            }
        }
        $reference = self::fingerprint($this->as($this->f, 'DELETE', '/api/v1/comments/'.self::UNKNOWN));
        self::assertSame($reference, self::fingerprint($this->as($this->f, 'DELETE', '/api/v1/comments/'.$commentId)));

        foreach ([$this->f, $this->s] as $who) {
            self::assertSame(204, $this->as($who, 'POST', '/api/v1/post-views', ['views' => [['postId' => $id, 'watchedMs' => 5_000]]])->getStatusCode());
        }
        self::assertSame(204, $this->as($this->c, 'POST', '/api/v1/post-views', ['views' => [['postId' => $id, 'watchedMs' => 5_000]]])->getStatusCode());
        self::assertSame('1', (string) $this->db('SELECT COUNT(*) FROM post_view WHERE post_id = ?', $post));
        self::assertSame('1', (string) $this->db('SELECT view_count FROM post WHERE id = ?', $post));
        self::assertSame('1', (string) $this->db('SELECT like_count FROM post WHERE id = ?', $post));

        // C leaves the close friends: the like can still be removed (F14), nothing else.
        self::assertLessThan(300, $this->as($this->o, 'DELETE', '/api/v1/me/close-friends/closefriend')->getStatusCode());
        self::assertSame(404, $this->as($this->c, 'PUT', '/api/v1/posts/'.$id.'/like')->getStatusCode());
        self::assertSame(['liked' => false, 'likeCount' => 0], $this->decode($this->as($this->c, 'DELETE', '/api/v1/posts/'.$id.'/like')));
        self::assertSame(404, $this->as($this->c, 'GET', '/api/v1/posts/'.$id.'/comments')->getStatusCode());
    }

    public function testFollowingFeedShowsOnlyPlayablePosts(): void
    {
        $forFollowers = $this->post($this->o, $this->followers);
        $forFriends = $this->post($this->o, $this->closeFriends);
        $ids = fn (User $u): array => array_column($this->decode($this->as($u, 'GET', '/api/v1/feed/following'))['items'], 'id');

        self::assertSame([$forFriends->getId()->toRfc4122(), $forFollowers->getId()->toRfc4122()], $ids($this->c));
        self::assertSame([$forFollowers->getId()->toRfc4122()], $ids($this->f));
        self::assertSame([], $ids($this->s));
        self::assertLessThan(300, $this->as($this->f, 'DELETE', '/api/v1/users/owner/follow')->getStatusCode());
        self::assertSame([], $ids($this->f));
    }

    private function post(User $author, Track $track): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        $this->em()->clear();

        return $post;
    }

    private function clip(User $user, Post $post): void
    {
        $r = $this->as($user, 'GET', '/api/v1/posts/'.$post->getId().'/stream-url');
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
    }

    /** The grant was issued 20 s ago: a 15 s listen fits the elapsed time. */
    private function ageGrants(): void
    {
        $this->em()->getConnection()->executeStatement("UPDATE listen_grant SET issued_at = now() - interval '20 seconds'");
    }

    private function db(string $sql, User|Track|Post $entity): mixed
    {
        return $this->em()->getConnection()->fetchOne($sql, [$entity->getId()->toRfc4122()]);
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

    private static function fingerprint(Response $r): string
    {
        $headers = $r->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return $r->getStatusCode()."\n".json_encode($headers)."\n".$r->getContent();
    }
}
