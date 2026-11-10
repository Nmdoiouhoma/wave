<?php

declare(strict_types=1);

namespace App\Tests\Functional\Post;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Enum\Visibility;
use App\Message\GenerateClip;
use App\Message\ProcessPostVisual;
use App\Message\PurgeObjects;
use App\Post\HashtagExtractor;
use App\Repository\PostRepository;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-30: posts (DATA_MODEL §9, openapi `posts`). */
final class PostTest extends WaveApiTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';
    private const string PNG = "\x89PNG\r\n\x1A\n";

    public function testCreateWithoutVisualIs202ProcessingAndQueuesTheClip(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));

        $r = $this->createPost($alice, ['trackId' => (string) $track->getId(), 'clipStartMs' => '0', 'clipDurationMs' => '30000', 'caption' => 'Night #Lo_Fi #lofi #LOFI vibes']);

        self::assertSame(202, $r->getStatusCode(), $r->getContent(false));
        $body = $this->json($r);
        self::assertSame('processing', $body['status']);
        self::assertSame(['lo_fi', 'lofi'], $body['hashtags']);
        self::assertNull($body['clip']);
        self::assertNull($body['visual']);
        self::assertTrue($body['isOwner']);
        self::assertFalse($body['likedByMe']);
        self::assertSame((string) $track->getId(), $body['track']['id']);
        self::assertSame('alice', $body['author']['username']);
        self::assertSame('/api/v1/posts/'.$body['id'], $r->getHeaders(false)['location'][0] ?? null);
        $clips = $this->queued('media', GenerateClip::class);
        self::assertCount(1, $clips);
        self::assertSame($body['id'], $clips[0]->postId);
        self::assertSame([], $this->queued('media', ProcessPostVisual::class));
        self::assertSame(['lo_fi' => 1, 'lofi' => 1], $this->hashtagCounts());
    }

    public function testCreateWithVisualSniffsTheContentAndQueuesBothJobs(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));

        // PNG bytes behind a misleading name and MIME: the content decides.
        $r = $this->createPost($alice, $this->fields($track), $this->file(self::PNG.'rest', 'photo.gif', 'image/gif'));
        self::assertSame(202, $r->getStatusCode(), $r->getContent(false));
        $id = $this->json($r)['id'];
        $post = $this->em()->find(Post::class, $id);
        self::assertNotNull($post);
        self::assertSame('image', $post->getVisualType()?->value);
        self::assertSame('quarantine/posts/'.$id.'/visual', $post->getVisualRawKey());
        self::assertTrue(static::getContainer()->get(ObjectStorage::class)->exists(StorageArea::Private, 'quarantine/posts/'.$id.'/visual'));
        self::assertCount(1, $this->queued('media', GenerateClip::class));
        self::assertCount(1, $this->queued('media', ProcessPostVisual::class));

        $mp4 = "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2";
        $r = $this->createPost($alice, $this->fields($track), $this->file($mp4, 'clip.bin', 'application/octet-stream'));
        self::assertSame(202, $r->getStatusCode(), $r->getContent(false));
        self::assertSame('video', $this->em()->find(Post::class, $this->json($r)['id'])?->getVisualType()?->value);
    }

    public function testCreationErrorsFollowTheContractOrder(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $bobArtist = $this->createArtist($bob, 'Bob Band');
        $own = $this->createTrack($this->createArtist($alice));

        $unknown = $this->createPost($alice, ['trackId' => self::UNKNOWN] + $this->fields($own));
        $this->assertProblem($unknown, 404, 'not_found');
        foreach ([
            'private' => $this->createTrack($bobArtist, 'P', visibility: Visibility::Private),
            'processing' => $this->createTrack($bobArtist, 'Q', ready: false),
            'hidden' => $this->hide($this->createTrack($bobArtist, 'H')),
        ] as $case => $track) {
            $r = $this->createPost($alice, $this->fields($track));
            $this->assertProblem($r, 404, 'not_found');
            self::assertSame($unknown->getContent(false), $r->getContent(false), $case);
        }
        $noClips = $this->createTrack($bobArtist, 'N', allowClips: false);
        $this->assertProblem($this->createPost($alice, $this->fields($noClips)), 422, 'clips_not_allowed');
        // Range violated too, but clips_not_allowed comes first.
        $this->assertProblem($this->createPost($alice, ['clipStartMs' => '170000'] + $this->fields($noClips)), 422, 'clips_not_allowed');
        $this->assertProblem($this->createPost($alice, $this->fields($this->hide($this->createTrack($this->createArtist($alice, 'A2'), 'Own hidden')))), 422, 'clips_not_allowed');
        $ownProcessing = $this->createTrack($this->managed($own)->getArtist(), 'Own processing', ready: false);
        $this->assertProblem($this->createPost($alice, $this->fields($ownProcessing)), 409, 'track_not_ready');
        // Own private track: allowed (author = owner).
        self::assertSame(202, $this->createPost($alice, $this->fields($this->createTrack($this->managed($own)->getArtist(), 'Own private', visibility: Visibility::Private)))->getStatusCode());

        $this->assertProblem($this->createPost($alice, ['clipStartMs' => '150001', 'clipDurationMs' => '30000'] + $this->fields($own)), 422, 'clip_out_of_range');
        self::assertSame(202, $this->createPost($alice, ['clipStartMs' => '150000', 'clipDurationMs' => '30000'] + $this->fields($own))->getStatusCode());
        foreach (['14999', '60001', '-1', '1.5', 'abc', ''] as $duration) {
            $this->assertProblem($this->createPost($alice, ['clipDurationMs' => $duration] + $this->fields($own)), 422, 'validation_failed');
        }
        foreach (['15000', '60000'] as $duration) {
            self::assertSame(202, $this->createPost($alice, ['clipDurationMs' => $duration] + $this->fields($own))->getStatusCode(), $duration);
        }
        $this->assertProblem($this->createPost($alice, ['status' => 'ready'] + $this->fields($own)), 422, 'validation_failed');
        $this->assertProblem($this->createPost($alice, ['caption' => str_repeat('a', 301)] + $this->fields($own)), 422, 'validation_failed');
        $this->assertProblem($this->createPost($alice, ['caption' => "a\0b"] + $this->fields($own)), 422, 'validation_failed');
        $this->assertProblem($this->createPost($alice, ['trackId' => 'nope'] + $this->fields($own)), 422, 'validation_failed');
        $this->assertProblem($this->createPost($alice, $this->fields($own), ['other' => $this->file(self::PNG, 'a.png', 'image/png')['visual']]), 422, 'validation_failed');

        $carol = $this->createUser('carol', verified: false);
        $this->assertProblem($this->createPost($carol, $this->fields($own)), 403, 'email_not_verified');
    }

    public function testOversizedOrWrongTypeVisualsLeaveNoRow(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $before = $this->postCount();
        $mp4Head = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom";

        $cases = [
            [413, 'payload_too_large', $this->file(self::PNG, 'big.png', 'image/png', 5_242_881)],
            [202, null, $this->file(self::PNG, 'max.png', 'image/png', 5_242_880)],
            [413, 'payload_too_large', $this->file($mp4Head, 'big.mp4', 'video/mp4', 15_728_641)],
            [415, 'unsupported_media_type', $this->file('GIF89a'.str_repeat("\0", 20), 'a.png', 'image/png')],
            [415, 'unsupported_media_type', $this->file('ID3'.str_repeat("\0", 20), 'a.mp4', 'video/mp4')],
            [415, 'unsupported_media_type', $this->file("\x00\x00\x00\x20ftypM4A \x00\x00\x00\x00M4A mp42", 'a.mp4', 'video/mp4')],
        ];
        foreach ($cases as [$status, $code, $file]) {
            $r = $this->createPost($alice, $this->fields($track), $file);
            if (null === $code) {
                self::assertSame($status, $r->getStatusCode(), $r->getContent(false));
                ++$before;
                continue;
            }
            $this->assertProblem($r, $status, $code);
        }
        self::assertSame($before, $this->postCount(), 'no post row left on 413/415');
        self::assertCount(1, $this->queued('media', GenerateClip::class));
    }

    public function testHashtagsAreCappedAtTenAndCountedExactly(): void
    {
        self::assertSame(['lo_fi', 'lofi'], HashtagExtractor::extract('#Lo_Fi #lofi #LOFI'));
        self::assertSame(['café', 'full'], HashtagExtractor::extract('#Café #ｆｕｌｌ #full'));
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $tags = implode(' ', array_map(static fn (int $i): string => '#t'.$i, range(1, 11)));

        $r = $this->createPost($alice, ['caption' => $tags] + $this->fields($track));
        self::assertSame(array_map(static fn (int $i): string => 't'.$i, range(1, 10)), $this->sorted($this->json($r)['hashtags'], natural: true));
        $this->createPost($alice, ['caption' => '#t1 #t2'] + $this->fields($track));
        $counts = $this->hashtagCounts();
        self::assertSame(2, $counts['t1']);
        self::assertSame(1, $counts['t10']);
        self::assertArrayNotHasKey('t11', $counts);

        $this->assertNoContent($this->api('DELETE', '/api/v1/posts/'.$this->json($r)['id'], $alice));
        $counts = $this->hashtagCounts();
        self::assertSame(1, $counts['t1']);
        self::assertSame(0, $counts['t10']);
    }

    public function testReadVisibilityClipAndGrant(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $track = $this->createTrack($this->createArtist($alice));
        $processing = $this->makePost($bob, $track, ready: false);
        $ready = $this->makePost($bob, $track);

        $this->assertProblem($this->api('GET', '/api/v1/posts/'.$processing->getId(), $alice), 404, 'not_found');
        $own = $this->json($this->api('GET', '/api/v1/posts/'.$processing->getId(), $bob));
        self::assertSame('processing', $own['status']);
        self::assertNull($own['clip']);
        self::assertSame('processing', $this->json($this->api('GET', '/api/v1/posts/'.$processing->getId(), $mod))['status']);

        self::assertSame(0, $this->grantCount($alice));
        $r = $this->api('GET', '/api/v1/posts/'.$ready->getId(), $alice);
        self::assertSame(200, $r->getStatusCode());
        $body = $this->json($r);
        self::assertFalse($body['isOwner']);
        self::assertStringContainsString('/api/v1/stream/', $body['clip']['url']);
        self::assertSame(1, $this->grantCount($alice), 'issuing the clip writes the listen grant');

        $this->em()->getConnection()->executeStatement('INSERT INTO post_like (user_id, post_id, created_at) VALUES (:u, :p, now()) ON CONFLICT DO NOTHING', ['u' => $alice->getId()->toRfc4122(), 'p' => $ready->getId()->toRfc4122()]);
        self::assertTrue($this->json($this->api('GET', '/api/v1/posts/'.$ready->getId(), $alice))['likedByMe']);

        // The track owner withdraws clips: bob's post is no longer playable, 404 for others.
        $this->managed($track)->setAllowClips(false);
        $this->em()->flush();
        $this->assertProblem($this->api('GET', '/api/v1/posts/'.$ready->getId(), $alice), 404, 'not_found');
        $mine = $this->json($this->api('GET', '/api/v1/posts/'.$ready->getId(), $bob));
        self::assertSame('ready', $mine['status']);
        self::assertNotNull($mine['clip'], 'the author still sees and plays their post');

        // Track made private: invisible to bob, so his post is 404 even for him.
        $this->managed($track)->setVisibility(Visibility::Private);
        $this->em()->flush();
        $this->assertProblem($this->api('GET', '/api/v1/posts/'.$ready->getId(), $bob), 404, 'not_found');
    }

    public function testListUserPostsScopes(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $p1 = $this->makePost($bob, $track);
        $p2 = $this->makePost($bob, $track, ready: false);
        $p3 = $this->makePost($bob, $track);

        $others = $this->json($this->api('GET', '/api/v1/users/bob/posts', $alice));
        self::assertSame([(string) $p3->getId(), (string) $p1->getId()], array_column($others['items'], 'id'));
        $own = $this->json($this->api('GET', '/api/v1/users/bob/posts?limit=2', $bob));
        self::assertSame([(string) $p3->getId(), (string) $p2->getId()], array_column($own['items'], 'id'));
        self::assertIsString($own['nextCursor']);
        $next = $this->json($this->api('GET', '/api/v1/users/bob/posts?limit=2&cursor='.$own['nextCursor'], $bob));
        self::assertSame([(string) $p1->getId()], array_column($next['items'], 'id'));
        self::assertNull($next['nextCursor']);
        $this->assertProblem($this->api('GET', '/api/v1/users/nobody/posts', $alice), 404, 'not_found');
    }

    public function testPostStreamUrlAndClipStreaming(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $processing = $this->makePost($bob, $track, ready: false);
        $ready = $this->makePost($bob, $track);

        $this->assertProblem($this->api('GET', '/api/v1/posts/'.$processing->getId().'/stream-url', $bob), 409, 'track_not_ready');
        $this->assertProblem($this->api('GET', '/api/v1/posts/'.$processing->getId().'/stream-url', $alice), 404, 'not_found');
        $r = $this->api('GET', '/api/v1/posts/'.$ready->getId().'/stream-url', $alice);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertSame(1, $this->grantCount($alice));
        $path = (string) parse_url($this->json($r)['url'], \PHP_URL_PATH);

        $stream = $this->api('GET', $path);
        self::assertSame(200, $stream->getStatusCode(), $stream->getContent(false));
        self::assertStringEndsWith('audio/clips/'.$ready->getId().'.m4a', $stream->getHeaders(false)['x-accel-redirect'][0] ?? '');

        // Visibility is re-checked on every request.
        $this->hide($ready);
        $this->assertProblem($this->api('GET', $path), 404, 'not_found');
    }

    public function testDeleteRules(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $carol = $this->createUser('carol');
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $track = $this->createTrack($this->createArtist($alice));
        $visible = $this->makePost($bob, $track, caption: '#x', visual: true);
        $invisible = $this->makePost($bob, $track, ready: false);

        $this->assertProblem($this->api('DELETE', '/api/v1/posts/'.$visible->getId(), $carol), 403, 'forbidden');
        $this->assertProblem($this->api('DELETE', '/api/v1/posts/'.$invisible->getId(), $carol), 404, 'not_found');
        $this->assertProblem($this->api('DELETE', '/api/v1/posts/'.self::UNKNOWN, $carol), 404, 'not_found');

        $conn = $this->em()->getConnection();
        $comment = $this->addComment($visible, $carol);
        foreach ([['post', (string) $visible->getId()], ['comment', $comment], ['track', (string) $track->getId()]] as $i => [$type, $target]) {
            $conn->executeStatement("INSERT INTO report (id, reporter_id, target_type, target_id, reason, status, created_at) VALUES (gen_random_uuid(), :r, :t, :id, 'spam', 'open', now())", ['r' => $carol->getId()->toRfc4122(), 't' => $type, 'id' => $target]);
        }

        // Track made private: bob can no longer see the post, but can still delete it.
        $this->managed($track)->setVisibility(Visibility::Private);
        $this->em()->flush();
        $this->assertNoContent($this->api('DELETE', '/api/v1/posts/'.$visible->getId(), $bob));
        self::assertSame(['track'], $conn->fetchFirstColumn('SELECT target_type FROM report'));
        self::assertSame(0, $this->hashtagCounts()['x']);
        $purges = $this->queued('async', PurgeObjects::class);
        $byArea = [];
        foreach ($purges as $purge) {
            $byArea[$purge->area] = $purge->keys;
        }
        self::assertSame(['audio/clips/'.$visible->getId().'.m4a'], $byArea['private'] ?? null);
        self::assertSame(['images/posts/'.$visible->getId().'/v.webp'], $byArea['public'] ?? null);

        $this->assertNoContent($this->api('DELETE', '/api/v1/posts/'.$invisible->getId(), $mod));
        self::assertSame(0, $this->postCount());
    }

    public function testTrackDeletionRemovesEveryPostOnItWithExactCounts(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $other = $this->createTrack($this->managed($track)->getArtist(), 'Other');
        $this->makePost($bob, $track, caption: '#shared #gone');
        $this->makePost($alice, $track, caption: '#shared');
        $kept = $this->makePost($bob, $other, caption: '#shared');

        $this->assertNoContent($this->api('DELETE', '/api/v1/tracks/'.$track->getId(), $alice));

        self::assertSame([(string) $kept->getId()], $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM post'));
        self::assertSame(['gone' => 0, 'shared' => 1], $this->hashtagCounts());
    }

    public function testCreationIsRateLimitedPerDay(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        /** @var RateLimiterFactoryInterface $factory */
        $factory = static::getContainer()->get('limiter.post_create_user');
        $factory->create($alice->getId()->toRfc4122())->consume(30);

        $r = $this->createPost($alice, $this->fields($track));
        $this->assertProblem($r, 429, 'rate_limited');
        self::assertNotEmpty($r->getHeaders(false)['retry-after'][0] ?? null);
    }

    public function testTryMarkReadyNeedsEveryKey(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice));
        $post = $this->makePost($alice, $track, ready: false, visual: true, keys: false);
        $repo = static::getContainer()->get(PostRepository::class);

        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertFalse($repo->tryMarkReady($post->getId()), 'visual still missing');
        $post->setVisual('images/posts/'.$post->getId().'/v.webp', null);
        $this->em()->flush();
        self::assertTrue($repo->tryMarkReady($post->getId()));
        self::assertFalse($repo->tryMarkReady($post->getId()), 'idempotent');
        self::assertSame(MediaStatus::Ready, $this->reload($post)->getStatus());
        self::assertNull($post->getVisualRawKey());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<string, string>       $fields
     * @param array<string, UploadedFile> $files
     */
    private function createPost(User $as, array $fields, array $files = []): ResponseInterface
    {
        return $this->api('POST', '/api/v1/posts', $as, [
            'headers' => ['Content-Type' => 'multipart/form-data'],
            'extra' => ['parameters' => $fields, 'files' => $files],
        ]);
    }

    /** @return array<string, string> */
    private function fields(Track $track): array
    {
        return ['trackId' => $track->getId()->toRfc4122(), 'clipStartMs' => '1000', 'clipDurationMs' => '30000'];
    }

    /** @return array{visual: UploadedFile} */
    private function file(string $head, string $name, string $mime, int $size = 0): array
    {
        return ['visual' => new UploadedFile($this->uploadTempFile($head, $size), $name, $mime, null, true)];
    }

    private function makePost(User $author, Track $track, bool $ready = true, ?string $caption = null, bool $visual = false, bool $keys = true): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, $caption);
        $id = $post->getId()->toRfc4122();
        if ($visual) {
            $post->attachRawVisual(\App\Enum\VisualType::Image, 'quarantine/posts/'.$id.'/visual');
        }
        $this->em()->persist($post);
        $this->em()->flush();
        foreach (HashtagExtractor::extract($caption) as $name) {
            $this->em()->getConnection()->executeStatement(
                'INSERT INTO hashtag (id, name, post_count, created_at) VALUES (gen_random_uuid(), :n, 1, now()) ON CONFLICT (name) DO UPDATE SET post_count = hashtag.post_count + 1',
                ['n' => $name],
            );
            $this->em()->getConnection()->executeStatement('INSERT INTO post_hashtag (post_id, hashtag_id) SELECT :p, id FROM hashtag WHERE name = :n', ['p' => $id, 'n' => $name]);
        }
        if ($ready && $keys) {
            $post->setClipKey('audio/clips/'.$id.'.m4a');
            if ($visual) {
                $post->setVisual('images/posts/'.$id.'/v.webp', null);
            }
            $this->em()->flush();
            self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
            $this->em()->refresh($post);
        }

        return $post;
    }

    /**
     * @template T of Track|Post
     *
     * @param T $entity
     *
     * @return T
     */
    private function hide(Track|Post $entity): Track|Post
    {
        $table = $entity instanceof Track ? 'track' : 'post';
        $this->em()->getConnection()->executeStatement("UPDATE {$table} SET moderation = 'hidden' WHERE id = :id", ['id' => $entity->getId()->toRfc4122()]);
        $this->em()->clear();

        return $entity;
    }

    private function addComment(Post $post, User $author): string
    {
        $id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO comment (id, post_id, author_id, body, moderation, created_at) VALUES (:id, :p, :a, 'hi', 'visible', now())",
            ['id' => $id, 'p' => $post->getId()->toRfc4122(), 'a' => $author->getId()->toRfc4122()],
        );

        return $id;
    }

    /** @return array<string, int> */
    private function hashtagCounts(): array
    {
        $counts = [];
        foreach ($this->em()->getConnection()->fetchAllKeyValue('SELECT name, post_count FROM hashtag ORDER BY name') as $name => $count) {
            $counts[(string) $name] = (int) $count;
        }

        return $counts;
    }

    private function postCount(): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM post');
    }

    private function grantCount(User $user): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM listen_grant WHERE user_id = :u', ['u' => $user->getId()->toRfc4122()]);
    }

    private function assertNoContent(ResponseInterface $r): void
    {
        self::assertSame(204, $r->getStatusCode(), $r->getContent(false));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values, bool $natural = false): array
    {
        $natural ? natsort($values) : sort($values);

        return array_values($values);
    }
}
