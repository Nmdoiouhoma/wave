<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Tracks the temporary files of one job and deletes them all at the end
 * (the worker's /tmp is a size-limited tmpfs).
 */
final class TempFiles
{
    /** @var list<string> */
    private array $paths = [];

    /** Registers a file created elsewhere (e.g. ObjectStorage::downloadToTemp). */
    public function adopt(string $path): string
    {
        $this->paths[] = $path;

        return $path;
    }

    /** Reserves a new temp path with the given extension (file not created). */
    public function create(string $suffix = ''): string
    {
        $path = \sprintf('%s/wave-media-%s%s', rtrim(sys_get_temp_dir(), '/'), bin2hex(random_bytes(12)), $suffix);
        $this->paths[] = $path;

        return $path;
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->paths = [];
    }
}
