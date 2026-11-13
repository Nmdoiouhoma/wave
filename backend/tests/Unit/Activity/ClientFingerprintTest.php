<?php

declare(strict_types=1);

namespace App\Tests\Unit\Activity;

use App\Activity\ActivityJournal;
use App\Activity\ClientFingerprint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** W-70, DATA_MODEL §15: what of the client reaches the journal. */
final class ClientFingerprintTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function prefixes(): iterable
    {
        yield 'ipv4 /24' => ['203.0.113.77', '203.0.113.0/24'];
        yield 'ipv6 /48' => ['2001:db8:abcd:12:1:2:3:4', '2001:db8:abcd::/48'];
        yield 'ipv4-mapped is ipv4' => ['::ffff:198.51.100.9', '198.51.100.0/24'];
        yield 'garbage' => ['not-an-ip', null];
    }

    #[DataProvider('prefixes')]
    public function testIpPrefix(string $ip, ?string $expected): void
    {
        self::assertSame($expected, ClientFingerprint::ipPrefix($ip));
    }

    public function testIpHashIsKeyedSixteenBytesAndCanonical(): void
    {
        $hash = ClientFingerprint::ipHash('198.51.100.9', 'pepper-a');
        self::assertIsString($hash);
        self::assertSame(16, \strlen($hash));
        self::assertSame($hash, ClientFingerprint::ipHash('::ffff:198.51.100.9', 'pepper-a'));
        self::assertNotSame($hash, ClientFingerprint::ipHash('198.51.100.9', 'pepper-b'));
        self::assertNotSame($hash, ClientFingerprint::ipHash('198.51.100.10', 'pepper-a'));
        self::assertNotSame(substr(hash('sha256', '198.51.100.9', true), 0, 16), $hash, 'never an unkeyed hash');
        self::assertNull(ClientFingerprint::ipHash('nope', 'pepper-a'));
    }

    public function testUserAgentIsCleanedAndTruncated(): void
    {
        self::assertNull(ClientFingerprint::userAgent(null));
        self::assertNull(ClientFingerprint::userAgent(" \x00\x1b "));
        self::assertSame('Mozilla/5.0 x', ClientFingerprint::userAgent("Mozilla/5.0\x00 x\u{200B}"));
        $long = ClientFingerprint::userAgent(str_repeat('é', 300));
        self::assertSame(200, mb_strlen((string) $long));
        self::assertTrue(mb_check_encoding((string) ClientFingerprint::userAgent("ok \xff"), 'UTF-8'), 'invalid UTF-8 scrubbed');
    }

    public function testMetadataWhitelist(): void
    {
        $dirty = [
            'action' => 'hide',
            'reportCount' => 3,
            'reason' => 'spam',
            'email' => 'alice@example.test',
            'token' => 'abc',
            'password' => 'secret',
            'bucket' => 'login ip',             // space: not a token value
            'from' => 'alice@example.test',     // '@': rejected
            'to' => ['nested'],
            'note' => "  rude \x07 ".str_repeat('x', 600),
        ];
        $user = ActivityJournal::metadata($dirty, false);
        self::assertSame(['action' => 'hide', 'reportCount' => 3, 'reason' => 'spam'], $user);

        $admin = ActivityJournal::metadata($dirty, true);
        self::assertSame(['action', 'reportCount', 'reason', 'note'], array_keys($admin));
        self::assertSame(ActivityJournal::NOTE_MAX, mb_strlen((string) $admin['note']));
        self::assertStringStartsWith('rude  x', (string) $admin['note']);
    }
}
