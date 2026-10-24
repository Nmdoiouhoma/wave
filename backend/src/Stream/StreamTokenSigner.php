<?php

declare(strict_types=1);

namespace App\Stream;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * ADR 001: token = base64url(payload) . "." . base64url(HMAC-SHA256(payload, STREAM_SIGNING_KEY)),
 * payload `{"v":1,"k":"t"|"c","id":"<trackId|postId>","uid":"<viewerId>","exp":<unix>}`.
 */
final readonly class StreamTokenSigner
{
    public const string KIND_TRACK = 't';
    public const string KIND_CLIP = 'c';

    public function __construct(
        #[Autowire(env: 'STREAM_SIGNING_KEY')]
        private string $key,
        #[Autowire(env: 'int:STREAM_URL_TTL')]
        private int $ttl,
        private ClockInterface $clock,
    ) {
        if (\strlen($key) < 16) {
            throw new \InvalidArgumentException('STREAM_SIGNING_KEY must be at least 16 bytes.');
        }
    }

    /** @return array{token: string, expiresAt: \DateTimeImmutable} */
    public function sign(string $kind, Uuid $id, Uuid $viewerId): array
    {
        $exp = $this->clock->now()->getTimestamp() + $this->ttl;
        $payload = json_encode(['v' => 1, 'k' => $kind, 'id' => $id->toRfc4122(), 'uid' => $viewerId->toRfc4122(), 'exp' => $exp], \JSON_THROW_ON_ERROR);

        return [
            'token' => self::b64($payload).'.'.self::b64($this->mac($payload)),
            'expiresAt' => new \DateTimeImmutable('@'.$exp)->setTimezone(new \DateTimeZone('UTC')),
        ];
    }

    /**
     * @throws ApiProblemException 403 stream_token_invalid, 410 stream_token_expired
     */
    public function verify(string $token): StreamGrant
    {
        $parts = explode('.', $token);
        if (2 !== \count($parts) || \strlen($token) > 512) {
            throw self::invalid();
        }
        $payload = self::unb64($parts[0]);
        if (null === $payload || !hash_equals(self::b64($this->mac($payload)), $parts[1]) || self::b64($payload) !== $parts[0]) {
            throw self::invalid();
        }
        try {
            $data = json_decode($payload, true, 4, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid();
        }
        if (!\is_array($data) || 1 !== ($data['v'] ?? null) || !\in_array($data['k'] ?? null, [self::KIND_TRACK, self::KIND_CLIP], true)
            || !\is_string($data['id'] ?? null) || !Uuid::isValid($data['id'])
            || !\is_string($data['uid'] ?? null) || !Uuid::isValid($data['uid'])
            || !\is_int($data['exp'] ?? null)) {
            throw self::invalid();
        }
        if ($data['exp'] <= $this->clock->now()->getTimestamp()) {
            throw new ApiProblemException(ProblemCode::StreamTokenExpired, 'This stream URL has expired, request a new one.');
        }

        return new StreamGrant($data['k'], Uuid::fromString($data['id']), Uuid::fromString($data['uid']));
    }

    private static function invalid(): ApiProblemException
    {
        return new ApiProblemException(ProblemCode::StreamTokenInvalid, 'Invalid stream URL.');
    }

    private function mac(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->key, true);
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
