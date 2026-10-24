<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Message\SendVerificationEmail;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-05: register/login/refresh/logout, cookie for web and body for mobile, rotation, limits. */
final class SessionTest extends WaveApiTestCase
{
    private const array MOBILE = ['X-Wave-Client' => 'mobile'];

    public function testRegisterOpensAWebSessionWithCookie(): void
    {
        $response = $this->register('newbie', 'Newbie@Example.test');

        self::assertSame(201, $response->getStatusCode(), $response->getContent(false));
        $body = $this->json($response);
        self::assertSame('Bearer', $body['tokenType']);
        self::assertSame(900, $body['expiresIn']);
        self::assertArrayNotHasKey('refreshToken', $body, 'web never gets the refresh token in the body');
        self::assertArrayNotHasKey('refreshExpiresIn', $body);
        self::assertSame('newbie', $body['user']['username']);
        self::assertSame('newbie@example.test', $body['user']['email']);
        self::assertFalse($body['user']['emailVerified']);
        self::assertSame(['ROLE_USER'], $body['user']['roles']);
        self::assertTrue($body['user']['isMe']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $body['user']['createdAt']);

        $cookie = $this->refreshCookieHeader($response);
        self::assertNotNull($cookie);
        self::assertStringContainsStringIgnoringCase('httponly', $cookie);
        self::assertStringContainsStringIgnoringCase('samesite=strict', $cookie);
        self::assertStringContainsString('path=/api/v1/auth', $cookie);
        self::assertStringContainsString('max-age=2592000', strtolower($cookie));
        self::assertStringNotContainsStringIgnoringCase('secure;', $cookie.';', 'REFRESH_COOKIE_SECURE=0 in test');

        self::assertCount(1, $this->queued('async', SendVerificationEmail::class));
    }

    public function testRegisterMobileGetsRefreshTokenInBodyAndNoCookie(): void
    {
        $response = $this->register('mobi', 'mobi@example.test', self::MOBILE);

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        self::assertIsString($body['refreshToken']);
        self::assertSame(2592000, $body['refreshExpiresIn']);
        self::assertNull($this->refreshCookieHeader($response));
    }

    public function testRegisterConflictsAndValidation(): void
    {
        $this->createUser('taken', email: 'taken@example.test');

        $this->assertProblem($this->register('other', 'TAKEN@example.test'), 409, 'email_taken');
        $this->assertProblem($this->register('taken', 'free@example.test'), 409, 'username_taken');

        $response = $this->api('POST', '/api/v1/auth/register', null, ['json' => ['email' => 'nope', 'password' => 'short', 'username' => 'Bad Name', 'displayName' => ' ']]);
        $this->assertProblem($response, 422, 'validation_failed');
        $paths = array_values(array_unique(array_column($this->json($response)['violations'], 'propertyPath')));
        sort($paths);
        self::assertSame(['displayName', 'email', 'password', 'username'], $paths);

        $this->assertProblem($this->register('admin', 'admin@example.test'), 422, 'validation_failed');
        $response = $this->api('POST', '/api/v1/auth/register', null, ['json' => ['email' => 'x@example.test', 'password' => 'long enough pw', 'username' => 'xyz', 'displayName' => 'X', 'role' => 'ROLE_ADMIN']]);
        $this->assertProblem($response, 422, 'validation_failed');
        self::assertSame('role', $this->json($response)['violations'][0]['propertyPath']);
    }

    public function testMalformedJsonIsBadRequest(): void
    {
        $this->assertProblem($this->api('POST', '/api/v1/auth/register', null, ['body' => '{nope', 'headers' => ['Content-Type' => 'application/json']]), 400, 'malformed_request');
        $this->assertProblem($this->api('POST', '/api/v1/auth/register', null, ['body' => '[1]', 'headers' => ['Content-Type' => 'application/json']]), 400, 'malformed_request');
        $this->assertProblem($this->api('POST', '/api/v1/auth/register', null, ['body' => 'a=b', 'headers' => ['Content-Type' => 'application/x-www-form-urlencoded']]), 415, 'unsupported_media_type');
    }

    public function testLoginErrorsAreIdenticalForUnknownEmailAndWrongPassword(): void
    {
        $this->createUser('alice');

        $unknown = $this->login('ghost@example.test', self::PASSWORD);
        $wrong = $this->login('alice@example.test', 'wrong password!');

        $this->assertProblem($unknown, 401, 'invalid_credentials');
        $this->assertProblem($wrong, 401, 'invalid_credentials');
        self::assertSame($unknown->getContent(false), $wrong->getContent(false));

        $this->assertProblem($this->api('POST', '/api/v1/auth/login', null, ['json' => ['email' => 'a@b.c']]), 400, 'malformed_request');
    }

    public function testLoginIsCaseInsensitiveOnEmailAndWorksOnMobile(): void
    {
        $this->createUser('alice');

        $response = $this->login('ALICE@example.test', self::PASSWORD, self::MOBILE);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($this->json($response)['refreshToken']);
        $me = $this->api('GET', '/api/v1/me', null, ['headers' => ['Authorization' => 'Bearer '.$this->json($response)['accessToken']]]);
        self::assertSame(200, $me->getStatusCode());
        self::assertSame('alice', $this->json($me)['username']);
    }

    public function testSuspendedUserCannotLoginNorRefreshNorUseAnAccessToken(): void
    {
        $user = $this->createUser('bad');
        $refresh = $this->json($this->login('bad@example.test', self::PASSWORD, self::MOBILE))['refreshToken'];
        $access = $this->tokenFor($user);
        $user->suspend();
        $this->em()->flush();

        $this->assertProblem($this->login('bad@example.test', self::PASSWORD), 403, 'account_suspended');
        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $refresh]]), 403, 'account_suspended');
        $this->assertProblem($this->api('GET', '/api/v1/me', null, ['headers' => ['Authorization' => 'Bearer '.$access]]), 403, 'account_suspended');
    }

    public function testMobileRefreshRotatesAndRejectsReuse(): void
    {
        $this->createUser('alice');
        $first = $this->json($this->login('alice@example.test', self::PASSWORD, self::MOBILE))['refreshToken'];

        $rotated = $this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $first]]);
        self::assertSame(200, $rotated->getStatusCode(), $rotated->getContent(false));
        $second = $this->json($rotated)['refreshToken'];
        self::assertNotSame($first, $second);
        self::assertSame('alice', $this->json($rotated)['user']['username']);

        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $first]]), 401, 'refresh_token_invalid');
        self::assertSame(200, $this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $second]])->getStatusCode());
    }

    public function testWebRefreshUsesTheCookieOnlyAndClearsItWhenInvalid(): void
    {
        $this->createUser('alice');
        $login = $this->login('alice@example.test', self::PASSWORD);
        $cookie = $this->cookieValue($login);
        $this->forgetCookies();

        // A token in the body is ignored on web.
        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['json' => ['refreshToken' => $cookie]]), 401, 'refresh_token_invalid');

        $this->useRefreshCookie($cookie);
        $rotated = $this->api('POST', '/api/v1/auth/refresh');
        self::assertSame(200, $rotated->getStatusCode(), $rotated->getContent(false));
        self::assertArrayNotHasKey('refreshToken', $this->json($rotated));
        self::assertNotSame($cookie, $this->cookieValue($rotated));

        $this->useRefreshCookie($cookie);
        $reused = $this->api('POST', '/api/v1/auth/refresh');
        $this->assertProblem($reused, 401, 'refresh_token_invalid');
        self::assertStringContainsString('wave_refresh=deleted', (string) $this->refreshCookieHeader($reused));
    }

    public function testExpiredRefreshTokenIsRejected(): void
    {
        $this->createUser('alice');
        $token = $this->json($this->login('alice@example.test', self::PASSWORD, self::MOBILE))['refreshToken'];
        $this->em()->getConnection()->executeStatement("UPDATE refresh_token SET valid = now() - interval '1 minute' WHERE refresh_token = :t", ['t' => $token]);

        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => $token]]), 401, 'refresh_token_invalid');
    }

    public function testLogoutRevokesTheTokenAndIsIdempotent(): void
    {
        $this->createUser('alice');
        $login = $this->login('alice@example.test', self::PASSWORD);
        $cookie = $this->cookieValue($login);
        $this->useRefreshCookie($cookie);

        $logout = $this->api('POST', '/api/v1/auth/logout');
        self::assertSame(204, $logout->getStatusCode());
        self::assertStringContainsString('wave_refresh=deleted', (string) $this->refreshCookieHeader($logout));
        $this->useRefreshCookie($cookie);
        $this->assertProblem($this->api('POST', '/api/v1/auth/refresh'), 401, 'refresh_token_invalid');
        self::assertSame(204, $this->api('POST', '/api/v1/auth/logout')->getStatusCode());
        self::assertSame(204, $this->api('POST', '/api/v1/auth/logout', null, ['headers' => self::MOBILE, 'json' => ['refreshToken' => 'unknown']])->getStatusCode());
    }

    public function testExpiredAccessTokenSaysTokenExpired(): void
    {
        $user = $this->createUser('alice');
        /** @var \Lexik\Bundle\JWTAuthenticationBundle\Encoder\JWTEncoderInterface $encoder */
        $encoder = static::getContainer()->get('lexik_jwt_authentication.encoder');
        $jwt = $encoder->encode(['sub' => $user->getUserIdentifier(), 'exp' => time() - 10, 'iat' => time() - 1000]);

        $this->assertProblem($this->api('GET', '/api/v1/me', null, ['headers' => ['Authorization' => 'Bearer '.$jwt]]), 401, 'token_expired');
    }

    public function testLoginRateLimitPerIpAndEmail(): void
    {
        $this->createUser('alice');
        for ($i = 0; $i < 5; ++$i) {
            $this->assertProblem($this->login('alice@example.test', 'wrong password!'), 401, 'invalid_credentials');
        }
        $limited = $this->login('alice@example.test', self::PASSWORD);

        $this->assertProblem($limited, 429, 'rate_limited');
        self::assertGreaterThan(0, (int) $limited->getHeaders(false)['retry-after'][0]);
        // Another email from the same IP is still allowed (IP bucket is 20/15 min).
        $this->assertProblem($this->login('bob@example.test', 'whatever pw'), 401, 'invalid_credentials');
    }

    public function testRegisterRateLimitPerIp(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(201, $this->register('user'.$i, "user{$i}@example.test")->getStatusCode());
        }
        $this->assertProblem($this->register('user9', 'user9@example.test'), 429, 'rate_limited');
    }

    /** @param array<string, string> $headers */
    private function register(string $username, string $email, array $headers = []): ResponseInterface
    {
        return $this->api('POST', '/api/v1/auth/register', null, ['headers' => $headers, 'json' => [
            'email' => $email, 'password' => self::PASSWORD, 'username' => $username, 'displayName' => ucfirst($username),
        ]]);
    }

    /** @param array<string, string> $headers */
    private function login(string $email, string $password, array $headers = []): ResponseInterface
    {
        return $this->api('POST', '/api/v1/auth/login', null, ['headers' => $headers, 'json' => ['email' => $email, 'password' => $password]]);
    }

    private function refreshCookieHeader(ResponseInterface $response): ?string
    {
        foreach ($response->getHeaders(false)['set-cookie'] ?? [] as $header) {
            if (str_starts_with($header, 'wave_refresh=')) {
                return $header;
            }
        }

        return null;
    }

    private function cookieValue(ResponseInterface $response): string
    {
        $header = $this->refreshCookieHeader($response);
        self::assertNotNull($header);

        return explode(';', substr($header, \strlen('wave_refresh=')))[0];
    }
}
