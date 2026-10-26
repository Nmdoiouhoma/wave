<?php

declare(strict_types=1);

namespace App\Upload;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Synchronous pre-checks of multipart image uploads (audio goes direct to storage, ADR 009): presence (422), size (413) and the
 * declared MIME type/extension (415). This is a pre-filter only: the media worker
 * re-validates the content with ffprobe and never trusts these values (BRIEF, ADR 003).
 */
final class UploadedFileValidator
{
    public const int IMAGE_MAX_BYTES = 5_242_880;
    /** @var array<string, list<string>> MIME → accepted extensions */
    public const array IMAGE_TYPES = [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /** @param array<string, list<string>> $types */
    public static function require(Request $request, string $field, int $maxBytes, array $types): UploadedFile
    {
        $file = $request->files->get($field);
        if (null === $file && (int) $request->server->get('CONTENT_LENGTH', 0) > $maxBytes) {
            // PHP dropped the body (post_max_size exceeded).
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, 'The file is too large.');
        }
        if (!$file instanceof UploadedFile) {
            throw ApiProblemException::validation($field, 'A file is required.');
        }
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, 'The file is too large.');
        }
        if (\UPLOAD_ERR_OK !== $file->getError() || !$file->isValid()) {
            throw ApiProblemException::validation($field, 'The upload failed, please retry.');
        }
        $size = $file->getSize();
        if ($size > $maxBytes) {
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, \sprintf('The file must not exceed %d bytes.', $maxBytes));
        }
        if (0 === $size) {
            throw ApiProblemException::validation($field, 'The file is empty.');
        }

        $mime = strtolower(trim(explode(';', (string) $file->getClientMimeType())[0]));
        $extension = strtolower($file->getClientOriginalExtension());
        $allExtensions = array_merge(...array_values($types));
        $mimeOk = isset($types[$mime]);
        $extensionOk = '' === $extension || \in_array($extension, $allExtensions, true);
        // Some clients send application/octet-stream: accept it only with an allowed extension.
        $genericOk = \in_array($mime, ['', 'application/octet-stream'], true) && '' !== $extension && \in_array($extension, $allExtensions, true);
        if (!(($mimeOk && $extensionOk) || $genericOk)) {
            throw new ApiProblemException(ProblemCode::UnsupportedMediaType, 'Allowed types: '.implode(', ', array_keys($types)).'.');
        }

        return $file;
    }

    /** Content type recorded on the quarantine object (declared, untrusted). */
    public static function declaredType(UploadedFile $file): string
    {
        $mime = strtolower(trim(explode(';', (string) $file->getClientMimeType())[0]));

        return '' === $mime ? 'application/octet-stream' : $mime;
    }
}
