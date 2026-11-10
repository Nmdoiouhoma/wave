<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Repository\PostRepository;
use Symfony\Component\HttpFoundation\Response;

/** W-30: an invisible post (or track, for createPost) is indistinguishable from an unknown id. */
final class PostSecurityTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    public function testInvisiblePostAnswersExactlyLikeAnUnknownOne(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $track = $this->createTrack($this->createArtist($alice));
        $processing = $this->post($bob, $track, ready: false);
        $hidden = $this->post($bob, $track);
        $this->em()->getConnection()->executeStatement("UPDATE post SET moderation = 'hidden' WHERE id = :id", ['id' => $hidden->getId()->toRfc4122()]);
        $noClips = $this->post($bob, $this->createTrack($this->managed($track)->getArtist(), 'No clips'));
        $this->em()->getConnection()->executeStatement('UPDATE track SET allow_clips = false WHERE id = :id', ['id' => $noClips->getTrack()->getId()->toRfc4122()]);
        $suspended = $this->createUser('dave');
        $byDave = $this->post($suspended, $track);
        $this->managed($suspended)->suspend();
        $this->em()->flush();
        $this->em()->clear();

        foreach (['GET' => '', 'GET ' => '/stream-url', 'DELETE' => ''] as $method => $suffix) {
            $method = trim($method);
            $reference = self::fingerprint($this->as($carol, $method, '/api/v1/posts/'.self::UNKNOWN.$suffix));
            self::assertStringStartsWith('404', $reference);
            foreach (['processing' => $processing, 'hidden' => $hidden, 'clips withdrawn' => $noClips, 'suspended author' => $byDave] as $case => $post) {
                self::assertSame($reference, self::fingerprint($this->as($carol, $method, '/api/v1/posts/'.$post->getId().$suffix)), "$method$suffix $case");
            }
            self::assertSame($reference, self::fingerprint($this->as($carol, $method, '/api/v1/posts/not-a-uuid'.$suffix)), "$method$suffix malformed");
        }
        self::assertSame(4, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM post'), 'nothing deleted');
    }

    public function testCreatePostOnAnInvisibleTrackAnswersLikeAnUnknownTrack(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($bob);
        $tracks = [
            $this->createTrack($artist, 'Private', visibility: Visibility::Private),
            $this->createTrack($artist, 'Processing', ready: false),
        ];
        $reference = self::fingerprint($this->createPostAs($alice, self::UNKNOWN));
        self::assertStringStartsWith('404', $reference);
        foreach ($tracks as $track) {
            self::assertSame($reference, self::fingerprint($this->createPostAs($alice, $track->getId()->toRfc4122())), $track->getTitle());
        }
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM post'));
    }

    // ------------------------------------------------------------- helpers ---

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

    private function as(User $user, string $method, string $uri): Response
    {
        return $this->raw($method, $uri, null, ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user)]);
    }

    private function post(User $author, Track $track, bool $ready = true): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        if ($ready) {
            self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        }

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
