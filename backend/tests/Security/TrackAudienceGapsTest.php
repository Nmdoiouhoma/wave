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
 * Lot 1b qa (FB-18, ADR 012): audience paths not covered by TrackAudienceSecurityTest —
 * `/users/{u}/posts` lists, someone else's clip after the owner restricts the track, no shared
 * cache on restricted answers, non-owner mutations, close-friends input edges.
 */
final class TrackAudienceGapsTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    public function testUserPostsListFollowsTheViewersAudience(): void
    {
        [$o, $f, $c, $s] = [$this->createUser('owner'), $this->createUser('fan'), $this->createUser('close'), $this->createUser('stranger')];
        $this->em()->flush();
        $artist = $this->createArtist($o, 'O Artist', 'oartist');
        $posts = [];
        foreach (['public', 'followers', 'close_friends', 'private'] as $audience) {
            $track = $this->createTrack($artist, 'T '.$audience, visibility: Visibility::from($audience));
            $posts[$audience] = $this->post($o, $track)->getId()->toRfc4122();
        }
        foreach ([$f, $c] as $u) {
            self::assertSame(200, $this->as($u, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        }
        self::assertSame(200, $this->as($o, 'PUT', '/api/v1/me/close-friends/close')->getStatusCode());
        $this->em()->clear();

        $expected = [
            'owner' => ['public', 'followers', 'close_friends', 'private'],
            'fan' => ['public', 'followers'],
            'close' => ['public', 'followers', 'close_friends'],
            'stranger' => ['public'],
        ];
        foreach ([$o, $f, $c, $s] as $u) {
            $want = array_map(static fn (string $a): string => $posts[$a], $expected[$u->getUsername()]);
            sort($want);
            self::assertSame($want, $this->postIds($u, '/api/v1/users/owner/posts'), $u->getUsername());
        }
    }

    public function testSomeoneElsesClipDisappearsWhenTheOwnerRestrictsTheTrack(): void
    {
        [$o, $clipper, $f] = [$this->createUser('owner'), $this->createUser('clipper'), $this->createUser('fan')];
        $this->em()->flush();
        $track = $this->createTrack($this->createArtist($o, 'O Artist', 'oartist'), 'Shared');
        $postId = $this->post($clipper, $track)->getId()->toRfc4122();
        foreach ([$clipper, $f] as $u) {
            self::assertSame(200, $this->as($u, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        }
        $this->em()->clear();
        self::assertSame([$postId], $this->postIds($f, '/api/v1/users/clipper/posts'), 'public clip playable');

        $this->em()->getConnection()->executeStatement("UPDATE track SET visibility = 'followers' WHERE id = ?", [$track->getId()->toRfc4122()]);
        // A clip of someone else's track is playable only while the track is public (ADR 012 §5):
        // even a follower of the owner no longer gets it; its author still sees it (they follow).
        self::assertSame([], $this->postIds($f, '/api/v1/users/clipper/posts'));
        $reference = self::fingerprint($this->as($f, 'GET', '/api/v1/posts/'.self::UNKNOWN));
        self::assertSame($reference, self::fingerprint($this->as($f, 'GET', '/api/v1/posts/'.$postId)));
        self::assertSame([$postId], $this->postIds($clipper, '/api/v1/users/clipper/posts'));
        self::assertSame(200, $this->as($clipper, 'GET', '/api/v1/posts/'.$postId)->getStatusCode());
    }

    public function testRestrictedAnswersAreNeverStoredByASharedCache(): void
    {
        [$o, $f] = [$this->createUser('owner'), $this->createUser('fan')];
        $this->em()->flush();
        $track = $this->createTrack($this->createArtist($o, 'O Artist', 'oartist'), 'Zephyr', visibility: Visibility::Followers);
        self::assertSame(200, $this->as($f, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        $id = $track->getId()->toRfc4122();
        foreach (['/api/v1/tracks/'.$id, '/api/v1/tracks/'.$id.'/stream-url', '/api/v1/users/owner/tracks', '/api/v1/search?type=tracks&q=zephyr'] as $uri) {
            $r = $this->as($f, 'GET', $uri);
            self::assertSame(200, $r->getStatusCode(), $uri);
            $cc = (string) $r->headers->get('Cache-Control');
            self::assertStringContainsString('no-store', $cc, $uri);
            self::assertStringContainsString('private', $cc, $uri);
            self::assertStringNotContainsString('public', $cc, $uri);
        }
    }

    public function testNonOwnerMutationsOfARestrictedTrackDoNotLeakIt(): void
    {
        [$o, $f, $s] = [$this->createUser('owner'), $this->createUser('fan'), $this->createUser('stranger')];
        $this->em()->flush();
        $track = $this->createTrack($this->createArtist($o, 'O Artist', 'oartist'), 'Zephyr', visibility: Visibility::Followers);
        self::assertSame(200, $this->as($f, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        $id = $track->getId()->toRfc4122();

        foreach ([['PATCH', '', ['visibility' => 'public']], ['DELETE', '', null], ['DELETE', '/cover', null]] as [$method, $suffix, $body]) {
            $unknown = self::fingerprint($this->as($s, $method, '/api/v1/tracks/'.self::UNKNOWN.$suffix, $body));
            self::assertStringStartsWith('404', $unknown);
            self::assertSame($unknown, self::fingerprint($this->as($s, $method, '/api/v1/tracks/'.$id.$suffix, $body)), "S $method$suffix");
            self::assertSame(403, $this->as($f, $method, '/api/v1/tracks/'.$id.$suffix, $body)->getStatusCode(), "F $method$suffix");
        }
        // The owner cannot set an unknown audience.
        $r = $this->as($o, 'PATCH', '/api/v1/tracks/'.$id, ['visibility' => 'friends']);
        self::assertContains($r->getStatusCode(), [400, 422], (string) $r->getContent());
        self::assertSame('followers', $this->em()->getConnection()->fetchOne('SELECT visibility FROM track WHERE id = ?', [$id]));
    }

    public function testCloseFriendsInputEdges(): void
    {
        $o = $this->createUser('owner');
        $sus = $this->createUser('sus');
        $this->em()->flush();
        self::assertSame(200, $this->as($sus, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        $this->managed($sus)->suspend();
        $this->em()->flush();

        // A suspended follower cannot be added, and answers like an unknown user.
        $unknown = self::fingerprint($this->as($o, 'PUT', '/api/v1/me/close-friends/nobody'));
        self::assertStringStartsWith('404', $unknown);
        self::assertSame($unknown, self::fingerprint($this->as($o, 'PUT', '/api/v1/me/close-friends/sus')));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT count(*) FROM close_friend'));

        // Malformed scope (array, empty) and a username that is not a username.
        foreach (['?scope[]=members', '?scope=', '?scope=members%00'] as $q) {
            self::assertSame(400, $this->as($o, 'GET', '/api/v1/me/close-friends'.$q)->getStatusCode(), $q);
        }
        foreach (["x'%20OR%201=1--", '..%2Fowner', str_repeat('a', 300)] as $name) {
            self::assertContains($this->as($o, 'PUT', '/api/v1/me/close-friends/'.$name)->getStatusCode(), [404, 422], $name);
        }
    }

    // ------------------------------------------------------------- helpers ---

    /** @return list<string> sorted post ids */
    private function postIds(User $user, string $uri): array
    {
        $r = $this->as($user, 'GET', $uri);
        self::assertSame(200, $r->getStatusCode(), $uri.' '.$r->getContent());
        $ids = array_column(json_decode((string) $r->getContent(), true, 512, \JSON_THROW_ON_ERROR)['items'], 'id');
        sort($ids);

        return $ids;
    }

    /** @param array<string, mixed>|null $body */
    private function as(User $user, string $method, string $uri, ?array $body = null): Response
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user)];
        if ('PATCH' === $method) {
            $server['CONTENT_TYPE'] = 'application/merge-patch+json';
        }

        return $this->raw($method, $uri, null === $body ? null : (string) json_encode($body), $server);
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

    private static function fingerprint(Response $r): string
    {
        $headers = $r->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return $r->getStatusCode()."\n".json_encode($headers)."\n".$r->getContent();
    }
}
