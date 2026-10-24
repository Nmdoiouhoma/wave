<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * ADR 002: cursor = base64url(json payload) . base64url(HMAC-SHA256(payload, APP_SECRET)[0..16]).
 * The MAC is always 22 base64url characters, so no separator is needed.
 */
final readonly class CursorCodec
{
    private const int VERSION = 1;
    private const int MAC_BYTES = 16;
    private const int MAC_CHARS = 22;
    public const int MAX_LENGTH = 512;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /** @param list<string|int|float|bool|null> $keys sort values of the last item, ending with its id */
    public function encode(array $keys): string
    {
        $payload = json_encode(['v' => self::VERSION, 'k' => $keys], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return self::b64($payload).self::b64($this->mac($payload));
    }

    /**
     * @return list<string|int|float|bool|null>
     *
     * @throws ApiProblemException invalid_cursor
     */
    public function decode(string $cursor): array
    {
        if (\strlen($cursor) <= self::MAC_CHARS || \strlen($cursor) > self::MAX_LENGTH) {
            throw self::invalid();
        }
        $payload = self::unb64(substr($cursor, 0, -self::MAC_CHARS));
        if (null === $payload || !hash_equals(self::b64($this->mac($payload)), substr($cursor, -self::MAC_CHARS))) {
            throw self::invalid();
        }
        // Reject non-canonical encodings (ignored trailing bits): one cursor, one spelling.
        if (self::b64($payload) !== substr($cursor, 0, -self::MAC_CHARS)) {
            throw self::invalid();
        }
        try {
            $data = json_decode($payload, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }
        if (!\is_array($data) || self::VERSION !== ($data['v'] ?? null) || !\is_array($data['k'] ?? null) || !array_is_list($data['k'])) {
            throw self::invalid();
        }
        $keys = [];
        foreach ($data['k'] as $key) {
            if (null !== $key && !\is_scalar($key)) {
                throw self::invalid();
            }
            $keys[] = $key;
        }

        return $keys;
    }

    public static function invalid(): ApiProblemException
    {
        return new ApiProblemException(ProblemCode::InvalidCursor, 'The cursor is invalid.');
    }

    private function mac(string $payload): string
    {
        return substr(hash_hmac('sha256', $payload, $this->secret, true), 0, self::MAC_BYTES);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): ?string
    {
        if ('' === $encoded || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $encoded)) {
            return null;
        }
        $raw = base64_decode(strtr($encoded, '-_', '+/'), true);

        return false === $raw ? null : $raw;
    }
}
