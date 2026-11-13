<?php

declare(strict_types=1);

namespace App\Activity;

/**
 * DATA_MODEL §15 privacy rules for the request origin: the full IP is never stored. It becomes
 * a display prefix (IPv4 /24, IPv6 /48) and a keyed hash (first 16 bytes of
 * HMAC-SHA256(ip, ACTIVITY_IP_PEPPER)) used only to correlate events from one address.
 */
final class ClientFingerprint
{
    public const int USER_AGENT_MAX = 200;

    public static function ipPrefix(string $ip): ?string
    {
        $ip = self::canonical($ip);
        if (null === $ip) {
            return null;
        }
        if (filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);

            return \sprintf('%s.%s.%s.0/24', $parts[0], $parts[1], $parts[2]);
        }
        $packed = inet_pton($ip);
        if (false === $packed || 16 !== \strlen($packed)) {
            return null;
        }
        $prefix = inet_ntop(substr($packed, 0, 6).str_repeat("\0", 10));

        return false === $prefix ? null : $prefix.'/48';
    }

    /** 16 raw bytes, or null for an unusable address. */
    public static function ipHash(string $ip, string $pepper): ?string
    {
        $ip = self::canonical($ip);

        return null === $ip ? null : substr(hash_hmac('sha256', $ip, $pepper, true), 0, 16);
    }

    /** Control characters stripped, invalid UTF-8 replaced, cut to 200 characters; empty → null. */
    public static function userAgent(?string $userAgent): ?string
    {
        if (null === $userAgent) {
            return null;
        }
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', mb_scrub($userAgent, 'UTF-8'));
        $clean = trim((string) preg_replace('/\p{C}+/u', '', $clean));

        return '' === $clean ? null : mb_substr($clean, 0, self::USER_AGENT_MAX);
    }

    /** Valid IP in its canonical spelling; an IPv4-mapped IPv6 address is treated as IPv4. */
    private static function canonical(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if (false === $packed) {
            return null;
        }
        if (16 === \strlen($packed) && str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $canonical = inet_ntop($packed);

        return false === $canonical ? null : $canonical;
    }
}
