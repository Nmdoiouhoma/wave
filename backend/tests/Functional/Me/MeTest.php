<?php

declare(strict_types=1);

namespace App\Tests\Functional\Me;

use App\Message\ProcessImage;
use App\Message\PurgeObjects;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** W-06: me and profiles. */
final class MeTest extends WaveApiTestCase
{
    public function testGetMe(): void
    {
        $alice = $this->createUser('alice');

        $response = $this->api('GET', '/api/v1/me', $alice);

        self::assertSame(200, $response->getStatusCode());
        $me = $this->json($response);
        self::assertSame([
            'id', 'username', 'displayName', 'avatarUrl', 'bio', 'followersCount', 'followingCount', 'createdAt',
            'isMe', 'isFollowedByMe', 'email', 'emailVerified', 'roles',
        ], array_keys($me));
        self::assertSame($alice->getId()->toRfc4122(), $me['id']);
        self::assertNull($me['avatarUrl']);
        self::assertNull($me['bio']);
        self::assertTrue($me['emailVerified']);
        self::assertStringContainsString('no-store', $response->getHeaders()['cache-control'][0]);
    }

    public function testPatchMeIsPartial(): void
    {
        $alice = $this->createUser('alice');

        $response = $this->api('PATCH', '/api/v1/me', $alice, ['json' => ['bio' => '  Hello  '], 'headers' => ['Content-Type' => 'application/merge-patch+json']]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Hello', $this->json($response)['bio']);
        self::assertSame('Alice', $this->json($response)['displayName']);

        $response = $this->api('PATCH', '/api/v1/me', $alice, ['json' => ['displayName' => 'Alice B.', 'bio' => null]]);
        self::assertSame(200, $response->getStatusCode(), $response->getContent(false));
        self::assertSame('Alice B.', $this->json($response)['displayName']);
        self::assertNull($this->json($response)['bio']);
    }

    public function testPatchMeRejectsImmutableAndInvalidFields(): void
    {
        $alice = $this->createUser('alice');

        foreach ([['username' => 'mallory'], ['email' => 'm@example.test'], ['roles' => ['ROLE_ADMIN']]] as $body) {
            $response = $this->api('PATCH', '/api/v1/me', $alice, ['json' => $body]);
            $this->assertProblem($response, 422, 'validation_failed');
            self::assertSame(array_key_first($body), $this->json($response)['violations'][0]['propertyPath']);
        }
        $this->assertProblem($this->api('PATCH', '/api/v1/me', $alice, ['json' => ['displayName' => null]]), 422, 'validation_failed');
        $this->assertProblem($this->api('PATCH', '/api/v1/me', $alice, ['json' => ['displayName' => '   ']]), 422, 'validation_failed');
        $this->assertProblem($this->api('PATCH', '/api/v1/me', $alice, ['json' => ['displayName' => str_repeat('x', 51)]]), 422, 'validation_failed');
        $this->assertProblem($this->api('PATCH', '/api/v1/me', $alice, ['json' => ['bio' => 12]]), 422, 'validation_failed');

        $alice = $this->reload($alice);
        self::assertSame('alice', $alice->getUsername());
        self::assertSame(['ROLE_USER'], $alice->getRoles());
    }

    public function testChangePasswordRevokesRefreshTokens(): void
    {
        $this->createUser('alice');
        $login = $this->json($this->api('POST', '/api/v1/auth/login', null, ['headers' => ['X-Wave-Client' => 'mobile'], 'json' => ['email' => 'alice@example.test', 'password' => self::PASSWORD]]));
        $bearer = ['Authorization' => 'Bearer '.$login['accessToken']];

        $wrong = $this->api('POST', '/api/v1/me/password', null, ['headers' => $bearer, 'json' => ['currentPassword' => 'nope', 'newPassword' => 'a brand new password']]);
        $this->assertProblem($wrong, 422, 'validation_failed');
        self::assertSame('currentPassword', $this->json($wrong)['violations'][0]['propertyPath']);
        $this->assertProblem($this->api('POST', '/api/v1/me/password', null, ['headers' => $bearer, 'json' => ['currentPassword' => self::PASSWORD, 'newPassword' => 'short']]), 422, 'validation_failed');

        $ok = $this->api('POST', '/api/v1/me/password', null, ['headers' => $bearer, 'json' => ['currentPassword' => self::PASSWORD, 'newPassword' => 'a brand new password']]);
        self::assertSame(204, $ok->getStatusCode(), $ok->getContent(false));

        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['headers' => ['X-Wave-Client' => 'mobile'], 'json' => ['refreshToken' => $login['refreshToken']]]), 401, 'refresh_token_invalid');
        $this->assertProblem($this->api('POST', '/api/v1/auth/login', null, ['json' => ['email' => 'alice@example.test', 'password' => self::PASSWORD]]), 401, 'invalid_credentials');
        self::assertSame(200, $this->api('POST', '/api/v1/auth/login', null, ['json' => ['email' => 'alice@example.test', 'password' => 'a brand new password']])->getStatusCode());
    }

    public function testProfileByUsername(): void
    {
        $alice = $this->createUser('alice');
        $this->createUser('bob');

        $other = $this->api('GET', '/api/v1/users/bob', $alice);
        self::assertSame(200, $other->getStatusCode());
        $profile = $this->json($other);
        self::assertSame(['id', 'username', 'displayName', 'avatarUrl', 'bio', 'followersCount', 'followingCount', 'createdAt', 'isMe', 'isFollowedByMe'], array_keys($profile));
        self::assertFalse($profile['isMe']);
        self::assertTrue($this->json($this->api('GET', '/api/v1/users/alice', $alice))['isMe']);

        $this->assertProblem($this->api('GET', '/api/v1/users/nobody', $alice), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/users/BAD!', $alice), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/api/v1/users/bob'), 401, 'unauthenticated');
    }

    public function testAvatarUploadIsAsyncAndDeleteQueuesAPurge(): void
    {
        $alice = $this->createUser('alice');

        $response = $this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => ['file' => $this->image('a.png', 'image/png')]]]);

        self::assertSame(202, $response->getStatusCode(), $response->getContent(false));
        self::assertNull($this->json($response)['avatarUrl'], 'unchanged until processed');
        $messages = $this->queued('media', ProcessImage::class);
        self::assertCount(1, $messages);
        self::assertSame('user_avatar', $messages[0]->target);
        self::assertSame($alice->getId()->toRfc4122(), $messages[0]->targetId);
        self::assertMatchesRegularExpression('#^quarantine/avatars/users/'.$alice->getId()->toRfc4122().'/[0-9a-f-]{36}$#', $messages[0]->quarantineKey);
        self::assertTrue(static::getContainer()->get(ObjectStorage::class)->exists(StorageArea::Private, $messages[0]->quarantineKey));

        // Media handler result, then delete.
        $alice = $this->em()->find(\App\Entity\User::class, $alice->getId());
        self::assertNotNull($alice);
        $alice->setAvatarKey('images/avatars/users/'.$alice->getId()->toRfc4122().'/x.webp');
        $this->em()->flush();
        self::assertSame('http://wave.test/media/images/avatars/users/'.$alice->getId()->toRfc4122().'/x.webp', $this->json($this->api('GET', '/api/v1/me', $alice))['avatarUrl']);

        self::assertSame(204, $this->api('DELETE', '/api/v1/me/avatar', $alice)->getStatusCode());
        $purges = $this->queued('async', PurgeObjects::class);
        self::assertCount(1, $purges);
        self::assertSame('public', $purges[0]->area);
        self::assertSame(['images/avatars/users/'.$alice->getId()->toRfc4122().'/x.webp'], $purges[0]->keys);
        self::assertNull($this->json($this->api('GET', '/api/v1/me', $alice))['avatarUrl']);
    }

    public function testAvatarUploadPreChecks(): void
    {
        $alice = $this->createUser('alice');

        $this->assertProblem($this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => ['file' => $this->image('a.txt', 'text/plain')]]]), 415, 'unsupported_media_type');
        $this->assertProblem($this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => ['file' => $this->image('big.png', 'image/png', 5_242_881)]]]), 413, 'payload_too_large');
        $this->assertProblem($this->api('POST', '/api/v1/me/avatar', $alice, ['extra' => ['files' => []]]), 422, 'validation_failed');
        self::assertCount(0, $this->queued('media'));
    }

    private function image(string $name, string $mime, int $size = 64): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-test-');
        self::assertIsString($path);
        file_put_contents($path, str_repeat("\x89", $size));

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
