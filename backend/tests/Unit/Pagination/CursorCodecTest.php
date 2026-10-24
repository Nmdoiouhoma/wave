<?php

declare(strict_types=1);

namespace App\Tests\Unit\Pagination;

use App\Pagination\CursorCodec;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CursorCodecTest extends TestCase
{
    private CursorCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new CursorCodec('unit-test-secret');
    }

    public function testRoundTrip(): void
    {
        $keys = ['2026-10-24T08:15:30.123456Z', '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e', 42, null, 1.5, true];
        $cursor = $this->codec->encode($keys);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor, 'cursor is URL-safe');
        self::assertSame($keys, $this->codec->decode($cursor));
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $cursor = $this->codec->encode(['2026-10-24T08:15:30.000000Z', 'a']);
        $forged = $this->codec->encode(['2099-01-01T00:00:00.000000Z', 'a']);
        // Payload of one cursor + MAC of another.
        $mixed = substr($forged, 0, -22).substr($cursor, -22);

        $this->assertInvalid($mixed);
    }

    public function testCursorSignedWithAnotherSecretIsRejected(): void
    {
        $other = new CursorCodec('another-secret');

        $this->assertInvalid($other->encode(['x', 'y']));
    }

    public function testFlippedMacCharacterIsRejected(): void
    {
        $cursor = $this->codec->encode(['x', 'y']);
        $last = substr($cursor, -1);
        $this->assertInvalid(substr($cursor, 0, -1).('A' === $last ? 'B' : 'A'));
    }

    /** @return iterable<string, array{string}> */
    public static function garbage(): iterable
    {
        yield 'empty' => [''];
        yield 'short' => ['abc'];
        yield 'not base64url' => [str_repeat('*', 40)];
        yield 'too long' => [str_repeat('A', 600)];
    }

    #[DataProvider('garbage')]
    public function testGarbageIsRejected(string $cursor): void
    {
        $this->assertInvalid($cursor);
    }

    public function testWrongVersionIsRejectedEvenWhenSigned(): void
    {
        $payload = '{"v":2,"k":["a"]}';
        $mac = substr(hash_hmac('sha256', $payload, 'unit-test-secret', true), 0, 16);
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        $this->assertInvalid($b64($payload).$b64($mac));
    }

    private function assertInvalid(string $cursor): void
    {
        try {
            $this->codec->decode($cursor);
            self::fail('Expected invalid_cursor');
        } catch (ApiProblemException $e) {
            self::assertSame(ProblemCode::InvalidCursor, $e->problemCode);
            self::assertSame(400, $e->problemCode->status());
        }
    }
}
