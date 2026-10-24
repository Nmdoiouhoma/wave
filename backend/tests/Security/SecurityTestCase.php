<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Account\AccountTokenIssuer;
use App\Entity\User;
use App\Enum\AccountTokenPurpose;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * W-16 helpers: raw kernel requests (custom REMOTE_ADDR / headers), JWT forging,
 * account tokens and upload files.
 */
abstract class SecurityTestCase extends WaveApiTestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $f) {
            @unlink($f);
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    /**
     * Request through the KernelBrowser so that server params (REMOTE_ADDR, raw headers)
     * are fully controlled.
     *
     * @param array<string, string> $server
     */
    protected function raw(string $method, string $uri, ?string $body = null, array $server = []): Response
    {
        $browser = $this->client->getKernelBrowser();
        $server += ['HTTP_ACCEPT' => 'application/json'];
        if (null !== $body && !isset($server['CONTENT_TYPE'])) {
            $server['CONTENT_TYPE'] = 'application/json';
        }
        $browser->request($method, $uri, [], [], $server, $body);

        return $browser->getResponse();
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $server
     */
    protected function rawJson(string $method, string $uri, array $data, array $server = []): Response
    {
        return $this->raw($method, $uri, (string) json_encode($data), $server);
    }

    protected static function problemCode(Response $response): ?string
    {
        $data = json_decode((string) $response->getContent(), true);

        return \is_array($data) && \is_string($data['code'] ?? null) ? $data['code'] : null;
    }

    protected function issueAccountToken(User $user, AccountTokenPurpose $purpose): string
    {
        return static::getContainer()->get(AccountTokenIssuer::class)->issue($user, $purpose);
    }

    // ------------------------------------------------------------------ JWT ---

    protected static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} header and claims of a real token */
    protected function decodeJwt(string $jwt): array
    {
        [$h, $p] = explode('.', $jwt);
        $header = json_decode((string) base64_decode(strtr($h, '-_', '+/')), true);
        $claims = json_decode((string) base64_decode(strtr($p, '-_', '+/')), true);
        self::assertIsArray($header);
        self::assertIsArray($claims);

        /* @var array{0: array<string, mixed>, 1: array<string, mixed>} */
        return [$header, $claims];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    protected static function unsignedJwt(array $header, array $claims): string
    {
        return self::b64((string) json_encode($header)).'.'.self::b64((string) json_encode($claims));
    }

    /**
     * RS256 with the test private key (= a token the server itself could have issued).
     *
     * @param array<string, mixed> $claims
     */
    protected function rs256(array $claims, ?\OpenSSLAsymmetricKey $key = null): string
    {
        $key ??= openssl_pkey_get_private((string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/config/jwt/test_private.pem'), 'test-jwt-passphrase');
        self::assertNotFalse($key);
        $input = self::unsignedJwt(['typ' => 'JWT', 'alg' => 'RS256'], $claims);
        self::assertTrue(openssl_sign($input, $signature, $key, \OPENSSL_ALGO_SHA256));

        return $input.'.'.self::b64($signature);
    }

    protected function publicKeyPem(): string
    {
        return (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/config/jwt/test_public.pem');
    }

    protected function getMeWithBearer(string $jwt): Response
    {
        return $this->raw('GET', '/api/v1/me', null, ['HTTP_AUTHORIZATION' => 'Bearer '.$jwt]);
    }

    // -------------------------------------------------------------- uploads ---

    protected function tempFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'wave-sec-');
        self::assertIsString($path);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }

    protected function upload(string $content, string $clientName, string $clientMime): UploadedFile
    {
        return new UploadedFile($this->tempFile($content), $clientName, $clientMime, null, true);
    }

    /**
     * Sparse file of $size bytes.
     *
     * @param int<0, max> $size
     */
    protected function sizedUpload(int $size, string $clientName, string $clientMime): UploadedFile
    {
        $path = $this->tempFile('');
        $h = fopen($path, 'r+');
        self::assertIsResource($h);
        ftruncate($h, $size);
        fclose($h);

        return new UploadedFile($path, $clientName, $clientMime, null, true);
    }

    /** Smallest valid PNG (1x1). */
    protected static function tinyPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    }
}
