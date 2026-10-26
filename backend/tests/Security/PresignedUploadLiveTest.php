<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Storage\FlysystemObjectStorage;
use App\Storage\PresignedPost;
use App\Storage\StorageArea;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * W-66 (ADR 009) against the RUNNING dev stack (nginx + MinIO + Postgres + FPM): real
 * parallel HTTP requests, which an in-process kernel test cannot produce. Skipped when the
 * stack is unreachable. Creates throwaway users in the dev database and removes them.
 *
 * Run alone: docker compose exec -T php bin/phpunit tests/Security/PresignedUploadLiveTest.php
 */
final class PresignedUploadLiveTest extends TestCase
{
    private const string EDGE = 'http://nginx:8080';
    private const string PASSWORD = 'live security test password';

    private HttpClientInterface $http;
    private \PDO $db;
    private S3Client $s3;
    private string $bucket = 'wave-private';
    /** @var list<string> */
    private array $users = [];

    protected function setUp(): void
    {
        $this->http = HttpClient::create(['timeout' => 30, 'max_redirects' => 0]);
        try {
            $this->http->request('GET', self::EDGE.'/healthz')->getStatusCode();
        } catch (\Throwable) {
            self::markTestSkipped('dev stack not reachable');
        }
        $url = parse_url((string) getenv('DATABASE_URL'));
        if (!\is_array($url) || !isset($url['host'], $url['path'])) {
            self::markTestSkipped('DATABASE_URL not set');
        }
        $this->db = new \PDO(\sprintf('pgsql:host=%s;port=%d;dbname=%s', $url['host'], $url['port'] ?? 5432, ltrim($url['path'], '/')), $url['user'] ?? '', $url['pass'] ?? '', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->s3 = new S3Client(['version' => 'latest', 'region' => getenv('S3_REGION') ?: 'us-east-1', 'endpoint' => (string) getenv('S3_ENDPOINT'), 'use_path_style_endpoint' => true, 'credentials' => ['key' => (string) getenv('S3_ACCESS_KEY'), 'secret' => (string) getenv('S3_SECRET_KEY')]]);
    }

    protected function tearDown(): void
    {
        foreach ($this->users as $id) {
            $keys = $this->db->prepare('SELECT quarantine_key FROM track_upload WHERE user_id = :u');
            $keys->execute(['u' => $id]);
            foreach ($keys->fetchAll(\PDO::FETCH_COLUMN) as $key) {
                try {
                    $this->s3->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
                } catch (\Throwable) {
                }
            }
            $this->db->prepare("DELETE FROM messenger_messages WHERE body LIKE '%' || :t || '%'")->execute(['t' => $id]);
            foreach (['DELETE FROM track_upload WHERE user_id = :u', 'DELETE FROM track WHERE owner_id = :u', 'DELETE FROM artist WHERE owner_id = :u', 'DELETE FROM refresh_token WHERE username = :u', 'DELETE FROM app_user WHERE id = :u'] as $sql) {
                try {
                    $this->db->prepare($sql)->execute(['u' => $id]);
                } catch (\PDOException) {
                }
            }
        }
    }

    // ------------------------------------------------------------- races ---

    /** Two (here eight) concurrent `POST /tracks` with the same uploadId: exactly one track. */
    public function testConcurrentConsumeOfOneUploadCreatesExactlyOneTrack(): void
    {
        [$token, $userId] = $this->verifiedUser();
        $this->createArtist($token, 'Race Band');
        $uploadId = $this->uploadedSlot($token);

        $responses = [];
        for ($i = 0; $i < 8; ++$i) {
            $responses[] = $this->http->request('POST', self::EDGE.'/api/v1/tracks', ['headers' => $this->auth($token), 'json' => ['uploadId' => $uploadId, 'title' => 'Race '.$i, 'genreId' => 'pop']]);
        }
        $codes = array_map(static fn (ResponseInterface $r): int => $r->getStatusCode(), $responses);
        sort($codes);

        self::assertCount(1, array_filter($codes, static fn (int $c): bool => 202 === $c), 'codes: '.implode(',', $codes));
        self::assertSame([409], array_values(array_unique(array_filter($codes, static fn (int $c): bool => 202 !== $c))), 'codes: '.implode(',', $codes));
        self::assertSame(1, $this->countRows('SELECT count(*) FROM track WHERE owner_id = :u', $userId));
    }

    /**
     * Known gap (frontend): parallel `POST /tracks` without artistId for a user with no
     * artist. resolveArtist() reads "0 artists" before ArtistCreator takes the user lock,
     * so every request creates its own artist.
     */
    public function testParallelFirstUploadsCreateASingleAutoArtist(): void
    {
        [$token, $userId] = $this->verifiedUser();
        $slots = [];
        for ($i = 0; $i < 5; ++$i) {
            $slots[] = $this->uploadedSlot($token);
        }
        $responses = [];
        foreach ($slots as $i => $uploadId) {
            $responses[] = $this->http->request('POST', self::EDGE.'/api/v1/tracks', ['headers' => $this->auth($token), 'json' => ['uploadId' => $uploadId, 'title' => 'First '.$i, 'genreId' => 'pop']]);
        }
        $codes = array_map(static fn (ResponseInterface $r): int => $r->getStatusCode(), $responses);

        $artists = $this->countRows('SELECT count(*) FROM artist WHERE owner_id = :u', $userId);
        self::assertSame(1, $artists, \sprintf('one auto-created artist expected, got %d (codes %s)', $artists, implode(',', $codes)));
        self::assertSame(array_fill(0, 5, 202), $codes, 'with one artist, every upload must land on it');
    }

    public function testUploadIsBoundToItsOwner(): void
    {
        [$alice] = $this->verifiedUser();
        [$bob, $bobId] = $this->verifiedUser();
        $this->createArtist($bob, 'Bob Band');
        $aliceUpload = $this->uploadedSlot($alice);

        $r = $this->http->request('POST', self::EDGE.'/api/v1/tracks', ['headers' => $this->auth($bob), 'json' => ['uploadId' => $aliceUpload, 'title' => 'Stolen', 'genreId' => 'pop']]);
        self::assertSame(404, $r->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT count(*) FROM track WHERE owner_id = :u', $bobId));
    }

    public function testPendingCapHoldsUnderConcurrency(): void
    {
        [$token, $userId] = $this->verifiedUser();
        $responses = [];
        for ($i = 0; $i < 14; ++$i) {
            $responses[] = $this->http->request('POST', self::EDGE.'/api/v1/tracks/uploads', ['headers' => $this->auth($token), 'json' => ['filename' => "f{$i}.mp3", 'size' => 1000, 'contentType' => 'audio/mpeg']]);
        }
        $codes = array_map(static fn (ResponseInterface $r): int => $r->getStatusCode(), $responses);
        self::assertCount(10, array_filter($codes, static fn (int $c): bool => 201 === $c), implode(',', $codes));
        self::assertSame(10, $this->countRows("SELECT count(*) FROM track_upload WHERE user_id = :u AND status = 'pending'", $userId));
    }

    /**
     * The presigned policy stays valid 15 min and cannot be revoked: after `POST /tracks`
     * consumed the upload, the uploader can still overwrite the object the worker is about
     * to process (or re-create it after the worker deleted it). The processed key must not
     * be the one the browser can still write to.
     */
    public function testConsumedUploadObjectCannotBeReplacedBeforeProcessing(): void
    {
        [$token, $userId] = $this->verifiedUser();
        $this->createArtist($token, 'Swap Band');
        $r = $this->http->request('POST', self::EDGE.'/api/v1/tracks/uploads', ['headers' => $this->auth($token), 'json' => ['filename' => 'song.mp3', 'size' => 64, 'contentType' => 'audio/mpeg']]);
        $slot = $r->toArray();
        $post = new PresignedPost((string) $slot['url'], $slot['fields']);
        self::assertSame(204, $this->postForm($post, str_repeat("\xFF\xFB", 32), self::EDGE.'/upload/wave-private')[0]);
        $r = $this->http->request('POST', self::EDGE.'/api/v1/tracks', ['headers' => $this->auth($token), 'json' => ['uploadId' => $slot['uploadId'], 'title' => 'Swap', 'genreId' => 'pop']]);
        self::assertSame(202, $r->getStatusCode());

        [$status] = $this->postForm($post, str_repeat('Z', 1000), self::EDGE.'/upload/wave-private');
        $raw = $this->db->prepare('SELECT raw_key FROM track WHERE owner_id = :u');
        $raw->execute(['u' => $userId]);
        $rawKey = $raw->fetchColumn();
        self::assertTrue(
            $status >= 400 || $rawKey !== $slot['fields']['key'],
            \sprintf('re-POST after consume answered %d and the worker will process the browser-writable key %s', $status, (string) $rawKey),
        );
    }

    // --------------------------------------------------------- the policy ---

    /**
     * S3 POST semantics: every form field must be covered by a policy condition. Fields that
     * would change how the object is stored or answered must be refused.
     */
    public function testPolicyRejectsFieldsItDoesNotCover(): void
    {
        $storage = $this->storage();
        $key = 'quarantine/uploads/qa-security/'.bin2hex(random_bytes(6));
        $post = $storage->presignedPost($key, 1024, new \DateTimeImmutable('+15 minutes'));
        try {
            $extras = [
                'Content-Type' => ['Content-Type' => 'text/html'],
                'x-amz-meta-*' => ['x-amz-meta-owner' => 'someone-else'],
                'acl' => ['acl' => 'public-read'],
                'success_action_redirect' => ['success_action_redirect' => 'https://evil.example/'],
                'redirect' => ['redirect' => 'https://evil.example/'],
                'x-amz-website-redirect-location' => ['x-amz-website-redirect-location' => 'https://evil.example/'],
                'Cache-Control' => ['Cache-Control' => 'public, max-age=31536000'],
                'Content-Disposition' => ['Content-Disposition' => 'inline'],
                'tagging' => ['tagging' => '<Tagging><TagSet><Tag><Key>a</Key><Value>b</Value></Tag></TagSet></Tagging>'],
                'x-amz-storage-class' => ['x-amz-storage-class' => 'REDUCED_REDUNDANCY'],
            ];
            $accepted = [];
            foreach ($extras as $label => $fields) {
                [$status, $headers] = $this->postForm(new PresignedPost($post->url, $post->fields + $fields), 'abc', self::EDGE.'/upload/wave-private');
                if ($status < 400) {
                    $accepted[] = \sprintf('%s → %d%s', $label, $status, isset($headers['location']) ? ' Location: '.$headers['location'][0] : '');
                }
            }
            self::assertSame([], $accepted, 'fields outside the policy were accepted');
        } finally {
            $storage->delete(StorageArea::Private, $key);
        }
    }

    public function testPolicySizeKeyAndExpiryThroughNginx(): void
    {
        $storage = $this->storage();
        $key = 'quarantine/uploads/qa-security/'.bin2hex(random_bytes(6));
        $post = $storage->presignedPost($key, 1024, new \DateTimeImmutable('+15 minutes'));
        try {
            self::assertSame(204, $this->postForm($post, str_repeat('a', 1024), self::EDGE.'/upload/wave-private')[0]);
            self::assertGreaterThanOrEqual(400, $this->postForm($post, str_repeat('a', 1025), self::EDGE.'/upload/wave-private')[0], 'size');
            self::assertGreaterThanOrEqual(400, $this->postForm($post, '', self::EDGE.'/upload/wave-private')[0], 'empty');
            self::assertGreaterThanOrEqual(400, $this->postForm(new PresignedPost($post->url, ['key' => 'audio/tracks/overwrite.m4a'] + $post->fields), 'abc', self::EDGE.'/upload/wave-private')[0], 'other key');
            self::assertGreaterThanOrEqual(400, $this->postForm(new PresignedPost($post->url, ['key' => '${filename}'] + $post->fields), 'abc', self::EDGE.'/upload/wave-private')[0], '${filename}');
            $expired = $storage->presignedPost($key, 1024, new \DateTimeImmutable('-1 second'));
            self::assertGreaterThanOrEqual(400, $this->postForm($expired, 'abc', self::EDGE.'/upload/wave-private')[0], 'expired');
            // Anonymous POST without any policy (the bucket must not accept it).
            self::assertGreaterThanOrEqual(400, $this->postForm(new PresignedPost($post->url, ['key' => $key.'-anon']), 'abc', self::EDGE.'/upload/wave-private')[0], 'anonymous');
        } finally {
            $storage->delete(StorageArea::Private, $key);
        }
    }

    // ---------------------------------------------------- /upload/ edge ---

    public function testUploadLocationNeverReadsTheBucket(): void
    {
        $stmt = $this->db->query('SELECT audio_key FROM track WHERE audio_key IS NOT NULL LIMIT 1');
        $key = false === $stmt ? false : $stmt->fetchColumn();
        $key = \is_string($key) ? $key : 'audio/tracks/00000000-0000-0000-0000-000000000000.m4a';
        $paths = [
            '/upload/wave-private', '/upload/wave-private/', '/upload/wave-private/'.$key, '/upload/wave-private%2F'.str_replace('/', '%2F', $key),
            '/upload/wave-private?list-type=2', '/upload/wave-private?location', '/upload/wave-private?policy', '/upload/wave-private?uploads',
            '/upload/wave-private?delete', '/upload/', '/upload/wave-public/x', '/upload/wave-private/..%2F'.$key, '/upload//wave-private',
        ];
        foreach ($paths as $path) {
            foreach (['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS', 'POST'] as $method) {
                if ('POST' === $method && '/upload/wave-private' === $path) {
                    continue;
                }
                $r = $this->http->request($method, self::EDGE.$path, 'PUT' === $method ? ['body' => 'x'] : []);
                $status = $r->getStatusCode();
                $headers = $r->getHeaders(false);
                $body = 'HEAD' === $method ? '' : $r->getContent(false);
                self::assertNotSame(200, $status, "$method $path");
                self::assertStringNotContainsString('audio/', $headers['content-type'][0] ?? '', "$method $path");
                self::assertStringNotContainsString('ListBucketResult', $body, "$method $path");
                self::assertStringNotContainsString('<Policy', $body, "$method $path");
            }
        }
    }

    public function testMinioCorsOnlyAllowsTheFrontOrigin(): void
    {
        $minio = rtrim((string) getenv('S3_ENDPOINT'), '/');
        $front = rtrim(self::stackEnv('FRONT_PUBLIC_URL'), '/');
        $preflight = fn (string $origin, string $method): array => $this->http->request('OPTIONS', $minio.'/wave-private/x', ['headers' => ['Origin' => $origin, 'Access-Control-Request-Method' => $method]])->getHeaders(false);
        self::assertArrayNotHasKey('access-control-allow-origin', $preflight('https://evil.example', 'POST'));
        $ok = $preflight($front, 'POST');
        self::assertSame($front, $ok['access-control-allow-origin'][0] ?? null);
        fwrite(\STDERR, \sprintf("\n[w-66] MinIO CORS for %s: methods=%s\n", $front, $ok['access-control-allow-methods'][0] ?? '-'));
    }

    // ------------------------------------------------------------ helpers ---

    /**
     * Throwaway verified user inserted in the dev database, with an access JWT signed by the
     * dev key (avoids burning the register/login rate limits of this container's IP).
     *
     * @return array{string, string} access token, user id
     */
    private function verifiedUser(): array
    {
        $name = 'qasec'.bin2hex(random_bytes(4));
        $id = \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        $this->db->prepare("INSERT INTO app_user (id, email, password, username, display_name, roles, email_verified_at, created_at) VALUES (:id, :e, :p, :u, :d, '[]', now(), now())")
            ->execute(['id' => $id, 'e' => $name.'@example.test', 'p' => password_hash(self::PASSWORD, \PASSWORD_BCRYPT), 'u' => $name, 'd' => 'QA '.$name]);
        $this->users[] = $id;

        $key = openssl_pkey_get_private((string) file_get_contents(\dirname(__DIR__, 2).'/config/jwt/private.pem'), self::stackEnv('JWT_PASSPHRASE'));
        if (false === $key) {
            self::markTestSkipped('dev JWT key not readable');
        }
        $b64 = static fn (string $raw): string => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $input = $b64((string) json_encode(['typ' => 'JWT', 'alg' => 'RS256'])).'.'.$b64((string) json_encode(['iat' => time(), 'exp' => time() + 600, 'roles' => ['ROLE_USER'], 'sub' => $id, 'sid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122()]));
        openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256);

        return [$input.'.'.$b64($signature), $id];
    }

    private function createArtist(string $token, string $name): void
    {
        $r = $this->http->request('POST', self::EDGE.'/api/v1/artists', ['headers' => $this->auth($token), 'json' => ['name' => $name.' '.bin2hex(random_bytes(3))]]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
    }

    private function uploadedSlot(string $token): string
    {
        $r = $this->http->request('POST', self::EDGE.'/api/v1/tracks/uploads', ['headers' => $this->auth($token), 'json' => ['filename' => 'song.mp3', 'size' => 64, 'contentType' => 'audio/mpeg']]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
        $slot = $r->toArray();
        [$status] = $this->postForm(new PresignedPost((string) $slot['url'], $slot['fields']), str_repeat("\xFF\xFB", 32), self::EDGE.'/upload/wave-private');
        self::assertSame(204, $status);

        return (string) $slot['uploadId'];
    }

    /** @return array{int, array<string, list<string>>} */
    private function postForm(PresignedPost $post, string $content, string $url): array
    {
        $parts = $post->fields;
        $parts['file'] = new DataPart($content, 'song.mp3', 'audio/mpeg');
        $form = new FormDataPart($parts);
        $r = $this->http->request('POST', $url, ['headers' => $form->getPreparedHeaders()->toArray(), 'body' => $form->bodyToString()]);

        return [$r->getStatusCode(), $r->getHeaders(false)];
    }

    private function storage(): FlysystemObjectStorage
    {
        return new FlysystemObjectStorage(new Filesystem(new AwsS3V3Adapter($this->s3, $this->bucket)), new Filesystem(new AwsS3V3Adapter($this->s3, 'wave-public')), 'http://wave.test/media', $this->s3, $this->bucket, rtrim((string) getenv('S3_ENDPOINT'), '/'));
    }

    /** Container value at process start: /proc/self/environ keeps the original block, phpunit.dist.xml only overrides getenv()/$_ENV. */
    private static function stackEnv(string $name): string
    {
        foreach (explode("\0", (string) @file_get_contents('/proc/self/environ')) as $pair) {
            if (str_starts_with($pair, $name.'=')) {
                return substr($pair, \strlen($name) + 1);
            }
        }
        self::markTestSkipped($name.' of the running stack is not readable');
    }

    /** @return array<string, string> */
    private function auth(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function countRows(string $sql, string $userId): int
    {
        $s = $this->db->prepare($sql);
        $s->execute(['u' => $userId]);

        return (int) $s->fetchColumn();
    }
}
