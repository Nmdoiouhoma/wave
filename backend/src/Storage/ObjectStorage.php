<?php

declare(strict_types=1);

namespace App\Storage;

/**
 * The only way to touch objects (ADR 003). Signatures are a shared contract with
 * media-infra (CONVENTIONS §7): do not change them without the architect.
 */
interface ObjectStorage
{
    /** @param resource $stream */
    public function write(StorageArea $area, string $key, $stream, string $contentType): void;

    /** @return resource */
    public function readStream(StorageArea $area, string $key);

    /** Downloads to a local temp file for ffmpeg; caller deletes it. */
    public function downloadToTemp(StorageArea $area, string $key): string;

    public function exists(StorageArea $area, string $key): bool;

    public function size(StorageArea $area, string $key): int;

    /** Idempotent: missing key is not an error. */
    public function delete(StorageArea $area, string $key): void;

    /** Server-side copy inside one area (overwrites $to). */
    public function copy(StorageArea $area, string $from, string $to): void;

    /** Public area only: absolute URL (MEDIA_PUBLIC_BASE_URL/key). */
    public function publicUrl(string $key): string;

    /** Private area only: nginx internal path for X-Accel-Redirect, e.g. /_protected/private/audio/tracks/{id}.m4a */
    public function internalRedirectPath(string $key): string;

    /** Private area only: browser-facing presigned S3 POST (ADR 009), URL built on S3_PRESIGN_ENDPOINT. */
    public function presignedPost(string $key, int $maxBytes, \DateTimeImmutable $expiresAt): PresignedPost;
}
