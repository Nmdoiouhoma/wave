<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SessionIssuer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * W-16: login/register/refresh rate limits, refresh rotation and reuse, cookie flags,
 * login CSRF, JWT algorithm confusion / expiry.
 */
final class SessionSecurityTest extends SecurityTestCase
{
    // ---------------------------------------------------------- rate limits ---

    public function testLoginIsLimitedPerIpAndEmail(): void
    {
        $this->createUser('alice');
        $codes = [];
        for ($i = 0; $i < 6; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => 'wrong password'], ['REMOTE_ADDR' => '203.0.113.10'])->getStatusCode();
        }
        self::assertSame([401, 401, 401, 401, 401, 429], $codes);

        // Case/space variants of the same email share the bucket.
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => ' ALICE@example.test', 'password' => 'wrong password'], ['REMOTE_ADDR' => '203.0.113.10']);
        self::assertSame(429, $r->getStatusCode());
        self::assertSame('rate_limited', self::problemCode($r));
        self::assertGreaterThan(0, (int) $r->headers->get('Retry-After'));

        // Even the right password is refused while limited (no oracle).
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD], ['REMOTE_ADDR' => '203.0.113.10']);
        self::assertSame(429, $r->getStatusCode());
    }

    public function testLoginIsLimitedPerIpAcrossEmails(): void
    {
        $codes = [];
        for ($i = 0; $i < 21; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/login', ['email' => "spray{$i}@example.test", 'password' => 'wrong password'], ['REMOTE_ADDR' => '203.0.113.11'])->getStatusCode();
        }
        self::assertSame(429, end($codes));
        self::assertCount(20, array_filter($codes, static fn (int $c): bool => 401 === $c));
    }

    public function testLoginLimitIgnoresForwardedForFromUntrustedPeer(): void
    {
        $this->createUser('alice');
        $codes = [];
        for ($i = 0; $i < 6; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => 'wrong password'], [
                'REMOTE_ADDR' => '203.0.113.12',
                'HTTP_X_FORWARDED_FOR' => '192.0.2.'.$i,
                'HTTP_FORWARDED' => 'for=192.0.2.'.$i,
                'HTTP_CLIENT_IP' => '192.0.2.'.$i,
            ])->getStatusCode();
        }
        self::assertSame(429, end($codes));
    }

    public function testRegisterIsLimitedPerIp(): void
    {
        $codes = [];
        for ($i = 0; $i < 6; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/register', [
                'email' => "new{$i}@example.test", 'password' => 'long enough password', 'username' => "newuser{$i}", 'displayName' => 'New',
            ], ['REMOTE_ADDR' => '203.0.113.13'])->getStatusCode();
        }
        self::assertSame([201, 201, 201, 201, 201, 429], $codes);
    }

    public function testRefreshIsLimitedPerIp(): void
    {
        $codes = [];
        for ($i = 0; $i < 31; ++$i) {
            $codes[] = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => 'x'.$i], ['REMOTE_ADDR' => '203.0.113.14', 'HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode();
        }
        self::assertSame(array_merge(array_fill(0, 30, 401), [429]), $codes);
    }

    // ----------------------------------------------------- login enumeration ---

    public function testLoginDoesNotRevealWhetherTheEmailExists(): void
    {
        $this->createUser('alice');
        $known = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => 'wrong password'], ['REMOTE_ADDR' => '198.51.100.1']);
        $unknown = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'ghost@example.test', 'password' => 'wrong password'], ['REMOTE_ADDR' => '198.51.100.2']);
        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($known->getContent(), $unknown->getContent());
    }

    /** Login CSRF: a cross-site HTML form can only send urlencoded/multipart/text-plain. */
    public function testLoginRejectsNonJsonBodies(): void
    {
        $this->createUser('alice');
        foreach (['text/plain', 'application/x-www-form-urlencoded', 'multipart/form-data; boundary=x'] as $type) {
            $r = $this->raw('POST', '/api/v1/auth/login', (string) json_encode(['email' => 'alice@example.test', 'password' => self::PASSWORD]), ['CONTENT_TYPE' => $type, 'REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)]);
            self::assertSame(415, $r->getStatusCode(), $type);
            self::assertNull($r->headers->getCookies()[0] ?? null, 'no session cookie for '.$type);
        }
    }

    // ------------------------------------------------- refresh / cookies ---

    public function testWebRefreshCookieFlags(): void
    {
        $this->createUser('alice');
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD]);
        self::assertSame(200, $r->getStatusCode());
        $body = json_decode((string) $r->getContent(), true);
        self::assertArrayNotHasKey('refreshToken', $body, 'web: refresh token never in the body');
        self::assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));

        $cookie = $this->refreshCookie($r);
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame(Cookie::SAMESITE_STRICT, $cookie->getSameSite());
        self::assertSame('/api/v1/auth', $cookie->getPath());
        self::assertNull($cookie->getDomain());
        self::assertGreaterThanOrEqual(43, \strlen((string) $cookie->getValue()), 'refresh token entropy');
        self::assertFalse($cookie->isSecure(), 'REFRESH_COOKIE_SECURE=0 in test');
    }

    public function testRefreshCookieIsSecureWhenConfigured(): void
    {
        $user = $this->createUser('alice');
        $c = static::getContainer();
        $issuer = new SessionIssuer(
            $c->get(\Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface::class),
            $c->get(\Gesdinet\JWTRefreshTokenBundle\Generator\RefreshTokenGeneratorInterface::class),
            $c->get(\Gesdinet\JWTRefreshTokenBundle\Model\RefreshTokenManagerInterface::class),
            $c->get(\App\Security\UserProvider::class),
            $c->get(\App\Mapper\UserMapper::class),
            $c->get(\App\Problem\ProblemResponseFactory::class),
            $c->get(Connection::class),
            900,
            2592000,
            true,
        );
        $cookie = $this->refreshCookie($issuer->open($user, Request::create('/api/v1/auth/login', 'POST')));
        self::assertTrue($cookie->isSecure());
    }

    public function testRotatedRefreshTokenCannotBeReplayed(): void
    {
        $this->createUser('alice');
        $first = $this->mobileLogin();
        $rotated = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $first], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(200, $rotated->getStatusCode());
        $second = json_decode((string) $rotated->getContent(), true)['refreshToken'];
        self::assertNotSame($first, $second);

        $replay = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $first], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(401, $replay->getStatusCode());
        self::assertSame('token_reused', self::problemCode($replay));
    }

    /**
     * ADR 008 (F2): replaying a rotated refresh token is the signature of a stolen token
     * (RFC 9700 §4.14.2): the whole family is revoked so that the thief's copy dies too.
     */
    public function testReplayOfRotatedRefreshTokenRevokesItsDescendants(): void
    {
        $this->createUser('alice');
        $stolen = $this->mobileLogin();
        // The thief refreshes first.
        $thief = json_decode((string) $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $stolen], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getContent(), true)['refreshToken'];
        // The legitimate client replays its (now rotated) token: reuse detected.
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $stolen], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode());

        $r = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $thief], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(401, $r->getStatusCode(), 'reuse of a rotated refresh token must revoke the token family');
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $this->createUser('alice');
        $token = $this->mobileLogin();
        static::getContainer()->get(Connection::class)->executeStatement("UPDATE refresh_token SET valid = clock_timestamp() - interval '1 second'");
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $token], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode());
    }

    public function testRefreshOfSuspendedUserRevokesEverything(): void
    {
        $alice = $this->createUser('alice');
        $a = $this->mobileLogin();
        $b = $this->mobileLogin();
        $alice = $this->reload($alice);
        $alice->suspend();
        $this->em()->flush();

        $r = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $a], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM refresh_token'));
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $b], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode());
    }

    public function testWebRefreshIgnoresBodyToken(): void
    {
        $this->createUser('alice');
        $token = $this->mobileLogin();
        $this->forgetCookies();
        // Web client (no X-Wave-Client): the body must not be accepted as a credential.
        $r = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $token]);
        self::assertSame(401, $r->getStatusCode());
    }

    // ------------------------------------------------------------------ JWT ---

    public function testAlgNoneIsRejected(): void
    {
        $alice = $this->createUser('alice');
        [, $claims] = $this->decodeJwt($this->tokenFor($alice));
        foreach (['none', 'None', 'NONE'] as $alg) {
            $jwt = self::unsignedJwt(['typ' => 'JWT', 'alg' => $alg], $claims);
            foreach ([$jwt.'.', $jwt] as $candidate) {
                $r = $this->getMeWithBearer($candidate);
                self::assertSame(401, $r->getStatusCode(), $alg);
                self::assertSame('unauthenticated', self::problemCode($r));
            }
        }
    }

    public function testHs256SignedWithThePublicKeyIsRejected(): void
    {
        $alice = $this->createUser('alice');
        [, $claims] = $this->decodeJwt($this->tokenFor($alice));
        $input = self::unsignedJwt(['typ' => 'JWT', 'alg' => 'HS256'], $claims);
        foreach ([$this->publicKeyPem(), trim($this->publicKeyPem())] as $secret) {
            $jwt = $input.'.'.self::b64(hash_hmac('sha256', $input, $secret, true));
            self::assertSame(401, $this->getMeWithBearer($jwt)->getStatusCode());
        }
    }

    public function testForeignKeyAndTamperedClaimsAreRejected(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $real = $this->tokenFor($alice);
        [, $claims] = $this->decodeJwt($real);

        $other = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        self::assertNotFalse($other);
        self::assertSame(401, $this->getMeWithBearer($this->rs256($claims, $other))->getStatusCode(), 'foreign key');

        // Swap the subject but keep alice's signature.
        [$h, , $s] = explode('.', $real);
        $tampered = $h.'.'.self::b64((string) json_encode(['sub' => $bob->getUserIdentifier()] + $claims)).'.'.$s;
        self::assertSame(401, $this->getMeWithBearer($tampered)->getStatusCode(), 'tampered sub');
    }

    public function testExpiredTokenAnswersTokenExpired(): void
    {
        $alice = $this->createUser('alice');
        [, $claims] = $this->decodeJwt($this->tokenFor($alice));
        $claims['iat'] = time() - 1000;
        $claims['exp'] = time() - 1;
        $r = $this->getMeWithBearer($this->rs256($claims));
        self::assertSame(401, $r->getStatusCode());
        self::assertSame('token_expired', self::problemCode($r));
    }

    public function testTokenWithoutExpiryIsRejected(): void
    {
        $alice = $this->createUser('alice');
        [, $claims] = $this->decodeJwt($this->tokenFor($alice));
        unset($claims['exp']);
        self::assertSame(401, $this->getMeWithBearer($this->rs256($claims))->getStatusCode());
    }

    public function testRolesClaimIsNotTrusted(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $hidden = $this->createTrack($this->createArtist($bob), visibility: \App\Enum\Visibility::Private);
        [, $claims] = $this->decodeJwt($this->tokenFor($alice));
        $claims['roles'] = ['ROLE_ADMIN', 'ROLE_MODERATOR'];
        $r = $this->raw('GET', '/api/v1/tracks/'.$hidden->getId(), null, ['HTTP_AUTHORIZATION' => 'Bearer '.$this->rs256($claims)]);
        self::assertSame(404, $r->getStatusCode(), 'roles come from the database, not from the token');
    }

    public function testTokenOfDeletedOrSuspendedUser(): void
    {
        $alice = $this->createUser('alice');
        $jwt = $this->tokenFor($alice);
        $alice->suspend();
        $this->em()->flush();
        $r = $this->getMeWithBearer($jwt);
        self::assertSame(403, $r->getStatusCode());
        self::assertSame('account_suspended', self::problemCode($r));

        $bob = $this->createUser('bob');
        $jwt = $this->tokenFor($bob);
        $this->em()->remove($bob);
        $this->em()->flush();
        self::assertSame(401, $this->getMeWithBearer($jwt)->getStatusCode());
    }

    public function testBearerIsOnlyReadFromTheAuthorizationHeader(): void
    {
        $alice = $this->createUser('alice');
        $jwt = $this->tokenFor($alice);
        self::assertSame(401, $this->raw('GET', '/api/v1/me?bearer='.$jwt.'&token='.$jwt)->getStatusCode());
        $this->client->getKernelBrowser()->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('BEARER', $jwt));
        self::assertSame(401, $this->raw('GET', '/api/v1/me')->getStatusCode());
    }

    // ------------------------------------------------ ADR 008 families ---

    public function testReuseRevokesOnlyThatFamilyNotOtherDevices(): void
    {
        $this->createUser('alice');
        $stolen = $this->mobileLogin();
        $otherDevice = $this->mobileLogin();
        $this->mobileRefresh($stolen); // rotates $stolen
        $r = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $stolen], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame('token_reused', self::problemCode($r));
        self::assertSame(200, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $otherDevice], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode(), 'unrelated session survives');
    }

    public function testWebReuseClearsTheCookie(): void
    {
        $this->createUser('alice');
        $login = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD]);
        $first = (string) $this->refreshCookie($login)->getValue();
        $this->useRefreshCookie($first);
        self::assertSame(200, $this->raw('POST', '/api/v1/auth/refresh')->getStatusCode());
        $this->useRefreshCookie($first);
        $r = $this->raw('POST', '/api/v1/auth/refresh');
        self::assertSame(401, $r->getStatusCode());
        self::assertSame('token_reused', self::problemCode($r));
        self::assertSame('', (string) $this->refreshCookie($r)->getValue());
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM refresh_token'));
    }

    public function testAccessTokenCarriesTheFamilyAsSid(): void
    {
        $this->createUser('alice');
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        $body = json_decode((string) $r->getContent(), true);
        [, $claims] = $this->decodeJwt($body['accessToken']);
        $family = static::getContainer()->get(Connection::class)->fetchOne('SELECT family_id FROM refresh_token WHERE refresh_token = :t', ['t' => $body['refreshToken']]);
        self::assertSame($family, $claims['sid'] ?? null);
        $refreshed = json_decode((string) $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $body['refreshToken']], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getContent(), true);
        self::assertSame($family, $this->decodeJwt($refreshed['accessToken'])[1]['sid'] ?? null, 'rotation keeps the family');
    }

    public function testLogoutRevokesTheWholeFamilyIncludingRotatedAncestors(): void
    {
        $this->createUser('alice');
        $a = $this->mobileLogin();
        $b = $this->mobileRefresh($a);
        self::assertSame(204, $this->rawJson('POST', '/api/v1/auth/logout', ['refreshToken' => $b], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode());
        self::assertSame(0, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM refresh_token'));
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $b], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode());
    }

    /** Thief holding the current device's refresh token: the password change rotates it away. */
    public function testPasswordChangeInvalidatesStolenCopyOfTheCurrentSession(): void
    {
        $this->createUser('alice');
        [$access, $stolen] = $this->mobileLoginFull();
        $r = $this->raw('POST', '/api/v1/me/password', (string) json_encode(['currentPassword' => self::PASSWORD, 'newPassword' => 'a brand new password']), ['HTTP_AUTHORIZATION' => 'Bearer '.$access, 'HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $fresh = json_decode((string) $r->getContent(), true)['refreshToken'];

        $replay = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $stolen], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(401, $replay->getStatusCode());
        self::assertSame('token_reused', self::problemCode($replay), 'the stolen copy is detected as reuse');
        self::assertSame(401, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $fresh], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode(), 'family revoked on both sides (ADR 008)');
    }

    public function testPasswordChangeDoesNotResurrectALoggedOutSession(): void
    {
        $this->createUser('alice');
        [$access, $refresh] = $this->mobileLoginFull();
        [, $claims] = $this->decodeJwt($access);
        self::assertSame(204, $this->rawJson('POST', '/api/v1/auth/logout', ['refreshToken' => $refresh], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode());

        $r = $this->raw('POST', '/api/v1/me/password', (string) json_encode(['currentPassword' => self::PASSWORD, 'newPassword' => 'a brand new password']), ['HTTP_AUTHORIZATION' => 'Bearer '.$access, 'HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(200, $r->getStatusCode());
        $new = json_decode((string) $r->getContent(), true);
        self::assertNotSame($claims['sid'], $this->decodeJwt($new['accessToken'])[1]['sid'] ?? null);
        self::assertSame(1, (int) static::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM refresh_token'));
    }

    /** A forged `sid` (other user's family) is ignored: the JWT signature check precedes it and DB updates are scoped to the user. */
    public function testPasswordChangeCannotTouchAnotherUsersFamily(): void
    {
        $this->createUser('alice');
        $this->createUser('bob');
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'bob@example.test', 'password' => self::PASSWORD], ['HTTP_X_WAVE_CLIENT' => 'mobile', 'REMOTE_ADDR' => '198.51.100.9']);
        $bobBody = json_decode((string) $r->getContent(), true);
        $bobSid = $this->decodeJwt($bobBody['accessToken'])[1]['sid'];

        [$aliceAccess] = $this->mobileLoginFull();
        [, $claims] = $this->decodeJwt($aliceAccess);
        $claims['sid'] = $bobSid; // signed by us: simulates a server-issued token with a foreign sid
        $r = $this->raw('POST', '/api/v1/me/password', (string) json_encode(['currentPassword' => self::PASSWORD, 'newPassword' => 'a brand new password']), ['HTTP_AUTHORIZATION' => 'Bearer '.$this->rs256($claims), 'HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(200, $r->getStatusCode());
        self::assertNotSame($bobSid, $this->decodeJwt(json_decode((string) $r->getContent(), true)['accessToken'])[1]['sid'] ?? null);
        self::assertSame(200, $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $bobBody['refreshToken']], ['HTTP_X_WAVE_CLIENT' => 'mobile'])->getStatusCode(), "bob's session untouched");
    }

    // -------------------------------------------------------------- helpers ---

    private function mobileLogin(): string
    {
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD], ['HTTP_X_WAVE_CLIENT' => 'mobile', 'REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $body = json_decode((string) $r->getContent(), true);
        self::assertSame([], $r->headers->getCookies(), 'mobile: no cookie');

        return $body['refreshToken'];
    }

    /** @return array{string, string} access and refresh tokens */
    private function mobileLoginFull(): array
    {
        $r = $this->rawJson('POST', '/api/v1/auth/login', ['email' => 'alice@example.test', 'password' => self::PASSWORD], ['HTTP_X_WAVE_CLIENT' => 'mobile', 'REMOTE_ADDR' => '198.51.100.'.random_int(1, 250)]);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        $body = json_decode((string) $r->getContent(), true);

        return [$body['accessToken'], $body['refreshToken']];
    }

    private function mobileRefresh(string $token): string
    {
        $r = $this->rawJson('POST', '/api/v1/auth/refresh', ['refreshToken' => $token], ['HTTP_X_WAVE_CLIENT' => 'mobile']);
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return json_decode((string) $r->getContent(), true)['refreshToken'];
    }

    private function refreshCookie(Response $r): Cookie
    {
        foreach ($r->headers->getCookies() as $cookie) {
            if (SessionIssuer::COOKIE === $cookie->getName()) {
                return $cookie;
            }
        }
        self::fail('no wave_refresh cookie');
    }
}
