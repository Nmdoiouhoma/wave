<?php

declare(strict_types=1);

namespace App\Feed;

use App\Pagination\CursorCodec;
use App\Pagination\Keyset;

/**
 * ADR 011 session cursor: `{"v":1,"r":"simple1","p":"u"|"s","seed":"…","now":"<µs UTC>","k":[…, "<id>"]}`.
 * `k` = last examined row: `[score, id]` in phase u, `[last_seen_at, id]` in phase s.
 * Any other ranker, phase, key shape or a tampered value → 400 `invalid_cursor`.
 */
final readonly class ForYouCursor
{
    public const string RANKER = 'simple1';
    public const string UNSEEN = 'u';
    public const string SEEN = 's';
    /** Canonical lowercase uuid as PostgreSQL prints it (any version: the key is only compared in SQL). */
    private const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @param 'u'|'s' $phase */
    public function __construct(
        public string $phase,
        public string $seed,
        public \DateTimeImmutable $now,
        public string $key,
        public string $id,
    ) {
    }

    /** Microsecond UTC timestamp, the format of `now` and of the phase-s key. */
    public static function formatTimestamp(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format(Keyset::TIMESTAMP_FORMAT);
    }

    public function encode(CursorCodec $codec): string
    {
        return $codec->encodePayload([
            'v' => 1,
            'r' => self::RANKER,
            'p' => $this->phase,
            'seed' => $this->seed,
            'now' => self::formatTimestamp($this->now),
            'k' => [$this->key, $this->id],
        ]);
    }

    public static function decode(CursorCodec $codec, string $cursor): self
    {
        $data = $codec->decodePayload($cursor);
        if (['k', 'now', 'p', 'r', 'seed', 'v'] !== self::sortedKeys($data)
            || 1 !== $data['v'] || self::RANKER !== $data['r']
            || !\in_array($data['p'], [self::UNSEEN, self::SEEN], true)
            || !\is_string($data['seed']) || 1 !== preg_match('/^[0-9a-f]{8}$/', $data['seed'])
            || !\is_string($data['now']) || null === ($now = self::parseTimestamp($data['now']))
            || !\is_array($data['k']) || !array_is_list($data['k']) || 2 !== \count($data['k'])
            || !\is_string($data['k'][0]) || !\is_string($data['k'][1]) || 1 !== preg_match(self::UUID, $data['k'][1])) {
            throw CursorCodec::invalid();
        }
        $key = $data['k'][0];
        $valid = self::UNSEEN === $data['p']
            ? 1 === preg_match('/^-?[0-9]{1,3}\.[0-9]{1,9}$|^-?[0-9]{1,3}$/', $key)
            : null !== self::parseTimestamp($key);
        if (!$valid) {
            throw CursorCodec::invalid();
        }

        return new self($data['p'], $data['seed'], $now, $key, $data['k'][1]);
    }

    /** Strict: the string must be exactly what formatTimestamp() produces. */
    private static function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        $at = \DateTimeImmutable::createFromFormat(Keyset::TIMESTAMP_FORMAT, $value, new \DateTimeZone('UTC'));

        return false !== $at && self::formatTimestamp($at) === $value ? $at : null;
    }

    /**
     * @param array<mixed> $data
     *
     * @return list<int|string>
     */
    private static function sortedKeys(array $data): array
    {
        $keys = array_keys($data);
        sort($keys);

        return $keys;
    }
}
