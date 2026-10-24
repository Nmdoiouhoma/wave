<?php

declare(strict_types=1);

namespace App\Tests\Unit\Message;

use App\Message;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * CONVENTIONS §8: message classes are a contract with media-infra (handlers are written
 * against these exact signatures). This test freezes them.
 */
final class MessageContractTest extends TestCase
{
    /** @return iterable<string, array{class-string, array<string, string>}> */
    public static function messages(): iterable
    {
        yield 'ProcessTrack' => [Message\ProcessTrack::class, ['trackId' => 'string']];
        yield 'ProcessImage' => [Message\ProcessImage::class, ['target' => 'string', 'targetId' => 'string', 'quarantineKey' => 'string']];
        yield 'GenerateClip' => [Message\GenerateClip::class, ['postId' => 'string']];
        yield 'ProcessPostVisual' => [Message\ProcessPostVisual::class, ['postId' => 'string']];
        yield 'PurgeObjects' => [Message\PurgeObjects::class, ['area' => 'string', 'keys' => 'array']];
        yield 'CleanupQuarantine' => [Message\CleanupQuarantine::class, []];
        yield 'SendVerificationEmail' => [Message\SendVerificationEmail::class, ['userId' => 'string']];
        yield 'SendPasswordResetEmail' => [Message\SendPasswordResetEmail::class, ['email' => 'string']];
        yield 'SendAccountDeletedEmail' => [Message\SendAccountDeletedEmail::class, ['email' => 'string', 'displayName' => 'string']];
        yield 'PurgeAccountTokens' => [Message\PurgeAccountTokens::class, []];
        yield 'RecomputeAffinity' => [Message\RecomputeAffinity::class, ['userId' => 'string']];
        yield 'RecomputeAffinityAll' => [Message\RecomputeAffinityAll::class, []];
        yield 'RecomputeTrending' => [Message\RecomputeTrending::class, []];
    }

    /**
     * @param class-string          $class
     * @param array<string, string> $payload
     */
    #[DataProvider('messages')]
    public function testSignature(string $class, array $payload): void
    {
        $reflection = new \ReflectionClass($class);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());

        $params = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $param) {
            $type = $param->getType();
            self::assertInstanceOf(\ReflectionNamedType::class, $type);
            self::assertTrue($param->isPromoted());
            $params[$param->getName()] = $type->getName();
        }
        self::assertSame($payload, $params);
    }
}
