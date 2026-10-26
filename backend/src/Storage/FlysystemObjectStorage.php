<?php

declare(strict_types=1);

namespace App\Storage;

use Aws\S3\PostObjectV4;
use Aws\S3\S3ClientInterface;
use League\Flysystem\Config;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToDeleteFile;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Two Flysystem filesystems selected by area: AWS S3 v3 adapters on MinIO/S3 in
 * dev/prod, in-memory adapters in the test environment (config/packages/flysystem.yaml).
 */
final readonly class FlysystemObjectStorage implements ObjectStorage
{
    public const string INTERNAL_PREFIX = '/_protected/private/';
    private const string PUBLIC_CACHE_CONTROL = 'public, max-age=31536000, immutable';

    public function __construct(
        #[Autowire(service: 'wave.storage.private')]
        private FilesystemOperator $private,
        #[Autowire(service: 'wave.storage.public')]
        private FilesystemOperator $public,
        #[Autowire(env: 'MEDIA_PUBLIC_BASE_URL')]
        private string $mediaPublicBaseUrl,
        #[Autowire(service: 'wave.s3_client')]
        private S3ClientInterface $s3,
        #[Autowire(env: 'S3_BUCKET_PRIVATE')]
        private string $privateBucket,
        #[Autowire(env: 'S3_PRESIGN_ENDPOINT')]
        private string $presignEndpoint,
    ) {
    }

    public function write(StorageArea $area, string $key, $stream, string $contentType): void
    {
        $config = [
            Config::OPTION_VISIBILITY => 'private',
            'mimetype' => $contentType,
            'ContentType' => $contentType,
        ];
        if (StorageArea::Public === $area) {
            // Public keys are versioned and never overwritten (ADR 003).
            $config['CacheControl'] = self::PUBLIC_CACHE_CONTROL;
        }
        $this->fs($area)->writeStream(self::check($key), $stream, $config);
    }

    public function readStream(StorageArea $area, string $key)
    {
        return $this->fs($area)->readStream(self::check($key));
    }

    public function downloadToTemp(StorageArea $area, string $key): string
    {
        $source = $this->readStream($area, $key);
        $path = tempnam(sys_get_temp_dir(), 'wave-');
        if (false === $path) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        $target = fopen($path, 'w');
        if (false === $target) {
            @unlink($path);
            throw new \RuntimeException('Cannot open the temporary file.');
        }
        try {
            if (false === stream_copy_to_stream($source, $target)) {
                throw new \RuntimeException('Cannot download the object.');
            }
        } catch (\Throwable $e) {
            fclose($target);
            @unlink($path);
            throw $e;
        } finally {
            if (\is_resource($source)) {
                fclose($source);
            }
        }
        fclose($target);

        return $path;
    }

    public function exists(StorageArea $area, string $key): bool
    {
        return $this->fs($area)->fileExists(self::check($key));
    }

    public function size(StorageArea $area, string $key): int
    {
        return $this->fs($area)->fileSize(self::check($key));
    }

    public function delete(StorageArea $area, string $key): void
    {
        try {
            $this->fs($area)->delete(self::check($key));
        } catch (UnableToDeleteFile $e) {
            if ($this->exists($area, $key)) {
                throw $e;
            }
        }
    }

    public function publicUrl(string $key): string
    {
        return rtrim($this->mediaPublicBaseUrl, '/').'/'.self::check($key);
    }

    public function internalRedirectPath(string $key): string
    {
        return self::INTERNAL_PREFIX.self::check($key);
    }

    /**
     * SigV4 POST policy: exact key, `content-length-range` [1, $maxBytes], expiry. The policy
     * is signed, not the host, so the form may target the nginx `/upload/` proxy (dev) or the
     * bucket endpoint (prod) through S3_PRESIGN_ENDPOINT, path-style.
     */
    public function presignedPost(string $key, int $maxBytes, \DateTimeImmutable $expiresAt): PresignedPost
    {
        $key = self::check($key);
        $post = new PostObjectV4(
            $this->s3,
            $this->privateBucket,
            ['key' => $key],
            [
                ['bucket' => $this->privateBucket],
                ['eq', '$key', $key],
                ['content-length-range', 1, $maxBytes],
            ],
            $expiresAt->getTimestamp(),
        );
        $fields = [];
        foreach ($post->getFormInputs() as $name => $value) {
            $fields[(string) $name] = (string) $value;
        }

        return new PresignedPost(rtrim($this->presignEndpoint, '/').'/'.rawurlencode($this->privateBucket), $fields);
    }

    private function fs(StorageArea $area): FilesystemOperator
    {
        return StorageArea::Private === $area ? $this->private : $this->public;
    }

    /** Keys are relative, slash-separated, without traversal segments. */
    private static function check(string $key): string
    {
        if ('' === $key || str_starts_with($key, '/') || 1 === preg_match('#(^|/)\.\.?(/|$)#', $key) || 1 !== preg_match('#^[A-Za-z0-9._/-]+$#', $key)) {
            throw new \InvalidArgumentException(\sprintf('Invalid object key "%s".', $key));
        }

        return $key;
    }
}
