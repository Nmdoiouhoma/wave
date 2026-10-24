<?php

declare(strict_types=1);

namespace App\Enum;

enum FailureReason: string
{
    case InvalidMedia = 'invalid_media';
    case UnsupportedCodec = 'unsupported_codec';
    case TooLong = 'too_long';
    case TooLarge = 'too_large';
    case ProcessingError = 'processing_error';
}
