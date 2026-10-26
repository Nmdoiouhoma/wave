<?php

declare(strict_types=1);

namespace App\Tests\Functional\Plumbing;

use App\Storage\FlysystemObjectStorage;
use App\Storage\PresignedPost;
use App\Storage\StorageArea;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

/**
 * W-60: the presigned POST really works against MinIO (skipped when the compose stack is
 * not reachable). The form is posted to S3_ENDPOINT directly: SigV4 POST signs the policy,
 * not the host, so this is the same form the browser sends through nginx `/upload/`.
 */
final class PresignedPostMinioTest extends KernelTestCase
{
    private S3Client $s3;
    private FlysystemObjectStorage $storage;
    private string $endpoint;
    private string $bucket;
    private string $lastBody = '';

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var S3Client $s3 */
        $s3 = static::getContainer()->get('wave.s3_client');
        $this->s3 = $s3;
        $this->endpoint = rtrim((string) getenv('S3_ENDPOINT'), '/');
        $this->bucket = (string) ($_SERVER['S3_BUCKET_PRIVATE'] ?? getenv('S3_BUCKET_PRIVATE') ?: 'wave-private');
        try {
            $this->s3->headBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable $e) {
            self::markTestSkipped('MinIO not reachable: '.$e->getMessage());
        }
        $fs = new Filesystem(new AwsS3V3Adapter($this->s3, $this->bucket));
        $public = new Filesystem(new AwsS3V3Adapter($this->s3, 'wave-public'));
        $this->storage = new FlysystemObjectStorage($fs, $public, 'http://wave.test/media', $this->s3, $this->bucket, $this->endpoint);
    }

    public function testFormUploadWithinPolicySucceedsAndViolationsFail(): void
    {
        $key = 'quarantine/uploads/test/'.bin2hex(random_bytes(8));
        $post = $this->storage->presignedPost($key, 1024, new \DateTimeImmutable('+15 minutes'));
        self::assertSame($this->endpoint.'/'.$this->bucket, $post->url);

        try {
            self::assertSame(204, $this->send($post, str_repeat('a', 1000)), $this->lastBody);
            self::assertTrue($this->storage->exists(StorageArea::Private, $key));
            self::assertSame(1000, $this->storage->size(StorageArea::Private, $key));

            self::assertGreaterThanOrEqual(400, $this->send($post, str_repeat('a', 1025)), 'above content-length-range');
            $otherKey = new PresignedPost($post->url, ['key' => $key.'-other'] + $post->fields);
            self::assertGreaterThanOrEqual(400, $this->send($otherKey, 'abc'), 'key is fixed by the policy');
            $expired = $this->storage->presignedPost($key.'-late', 1024, new \DateTimeImmutable('-1 minute'));
            self::assertGreaterThanOrEqual(400, $this->send($expired, 'abc'), 'expired policy');
            self::assertFalse($this->storage->exists(StorageArea::Private, $key.'-other'));
        } finally {
            $this->storage->delete(StorageArea::Private, $key);
        }
    }

    private function send(PresignedPost $post, string $content): int
    {
        $parts = $post->fields;
        $parts['file'] = new DataPart($content, 'song.mp3', 'audio/mpeg');
        $form = new FormDataPart($parts);

        $response = HttpClient::create()->request('POST', $post->url, [
            'headers' => $form->getPreparedHeaders()->toArray(),
            'body' => $form->bodyToString(),
        ]);
        $this->lastBody = $response->getContent(false);

        return $response->getStatusCode();
    }
}
