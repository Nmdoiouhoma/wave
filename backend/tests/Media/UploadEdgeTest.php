<?php

declare(strict_types=1);

namespace App\Tests\Media;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * W-61 / ADR 009: nginx `/upload/wave-private` accepts presigned POSTs only and
 * never exposes a read path to the private bucket. Needs the compose stack
 * (nginx + MinIO); skipped otherwise. Base URL override: WAVE_EDGE_URL.
 */
final class UploadEdgeTest extends TestCase
{
    private HttpClientInterface $http;
    private string $base;
    private S3Client $s3;
    private string $bucket;
    private string $key;
    private string $audio;

    protected function setUp(): void
    {
        $this->base = rtrim(getenv('WAVE_EDGE_URL') ?: 'http://nginx:8080', '/');
        $this->http = HttpClient::create(['timeout' => 10, 'max_redirects' => 0]);
        $endpoint = getenv('S3_ENDPOINT');
        $this->bucket = getenv('S3_BUCKET_PRIVATE') ?: 'wave-private';
        if (!$endpoint || !getenv('S3_ACCESS_KEY') || !getenv('S3_SECRET_KEY')) {
            self::markTestSkipped('S3_* env not set');
        }
        try {
            $this->http->request('GET', $this->base.'/healthz')->getStatusCode();
        } catch (\Throwable) {
            self::markTestSkipped('nginx not reachable at '.$this->base);
        }

        $this->s3 = new S3Client([
            'region' => getenv('S3_REGION') ?: 'us-east-1', 'version' => 'latest', 'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => (string) getenv('S3_ACCESS_KEY'), 'secret' => (string) getenv('S3_SECRET_KEY')],
        ]);
        $this->key = 'audio/tracks/w61-edge-'.bin2hex(random_bytes(6)).'.m4a';
        $this->audio = 'WAVE-PRIVATE-AUDIO-'.bin2hex(random_bytes(16));
        try {
            $this->s3->putObject(['Bucket' => $this->bucket, 'Key' => $this->key, 'Body' => $this->audio, 'ContentType' => 'audio/mp4']);
        } catch (\Throwable $e) {
            self::markTestSkipped('MinIO not reachable: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->s3, $this->key)) {
            $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->key]);
        }
    }

    public function testKnownPrivateAudioIsNotReadableThroughUpload(): void
    {
        // Sanity: the object exists and nginx's privileged path would serve it.
        self::assertSame($this->audio, (string) $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $this->key])['Body']);

        $paths = [
            '/upload/wave-private',
            '/upload/wave-private/',
            '/upload/wave-private/'.$this->key,
            '/upload/wave-private?prefix=audio/',
            '/upload/wave-private?list-type=2',
            '/upload/wave-private%2F'.str_replace('/', '%2F', $this->key),
            '/upload/'.$this->bucket.'/'.$this->key,
            '/upload/wave-public/x',
        ];
        foreach (['GET', 'HEAD'] as $method) {
            foreach ($paths as $path) {
                $r = $this->http->request($method, $this->base.$path);
                self::assertSame(404, $r->getStatusCode(), $method.' '.$path);
                $body = 'HEAD' === $method ? '' : $r->getContent(false);
                self::assertStringNotContainsString($this->audio, $body, $method.' '.$path);
                self::assertStringNotContainsString('ListBucketResult', $body, $method.' '.$path);
                self::assertArrayNotHasKey('x-amz-request-id', $r->getHeaders(false), $method.' '.$path);
            }
        }
    }

    public function testOnlyPostWithoutQueryStringReachesStorage(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) {
            self::assertSame(404, $this->http->request($method, $this->base.'/upload/wave-private', ['body' => 'x'])->getStatusCode(), $method);
        }
        self::assertSame(404, $this->http->request('POST', $this->base.'/upload/wave-private?delete', ['body' => 'x'])->getStatusCode());

        // An unsigned POST reaches MinIO and is refused there (no anonymous write).
        $r = $this->http->request('POST', $this->base.'/upload/wave-private', [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b'],
            'body' => "--b\r\nContent-Disposition: form-data; name=\"key\"\r\n\r\n{$this->key}\r\n--b\r\nContent-Disposition: form-data; name=\"file\"; filename=\"x\"\r\n\r\nEVIL\r\n--b--\r\n",
        ]);
        self::assertContains($r->getStatusCode(), [400, 403]);
        self::assertSame($this->audio, (string) $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $this->key])['Body'], 'object untouched');
    }

    public function testBodyLimits(): void
    {
        // 205 MB: above client_max_body_size 201m -> bare nginx 413.
        $r = $this->http->request('POST', $this->base.'/upload/wave-private', [
            'headers' => ['Content-Type' => 'multipart/form-data; boundary=b', 'Content-Length' => (string) (205 * 1024 * 1024)],
            'body' => (static function (): \Generator {
                yield str_repeat("\0", 1024);
            })(),
        ]);
        self::assertSame(413, $r->getStatusCode());

        // Track audio no longer goes through PHP: POST /api/v1/tracks is back to 1m.
        $r = $this->http->request('POST', $this->base.'/api/v1/tracks', ['body' => str_repeat('a', 2 * 1024 * 1024), 'headers' => ['Content-Type' => 'application/json']]);
        self::assertSame(413, $r->getStatusCode());
    }
}
