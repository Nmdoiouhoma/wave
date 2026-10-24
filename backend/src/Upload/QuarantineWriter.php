<?php

declare(strict_types=1);

namespace App\Upload;

use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Writes an upload to `quarantine/…` in the private bucket (DATA_MODEL §14). Nothing there is ever served. */
final readonly class QuarantineWriter
{
    public function __construct(private ObjectStorage $storage)
    {
    }

    public function write(UploadedFile $file, string $key): void
    {
        $stream = fopen($file->getPathname(), 'r');
        if (false === $stream) {
            throw new \RuntimeException('Cannot read the uploaded file.');
        }
        try {
            $this->storage->write(StorageArea::Private, $key, $stream, UploadedFileValidator::declaredType($file));
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
