<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * W-16: edge checks against the running nginx (ADR 001/006). Needs the compose stack;
 * skipped when nginx is unreachable. Override the base URL with WAVE_EDGE_URL.
 */
final class NginxEdgeTest extends TestCase
{
    private HttpClientInterface $http;
    private string $base;

    protected function setUp(): void
    {
        $this->base = rtrim(getenv('WAVE_EDGE_URL') ?: 'http://nginx:8080', '/');
        $this->http = HttpClient::create(['timeout' => 5, 'max_redirects' => 0]);
        try {
            $this->http->request('GET', $this->base.'/healthz')->getStatusCode();
        } catch (\Throwable) {
            self::markTestSkipped('nginx not reachable at '.$this->base);
        }
    }

    public function testProtectedLocationIsNotReachableDirectly(): void
    {
        foreach ([
            '/_protected/private/audio/tracks/00000000-0000-0000-0000-000000000000.m4a',
            '/_protected/private/',
            '/_protected/',
            '/_protected/private/quarantine/tracks/x/source',
        ] as $path) {
            $r = $this->http->request('GET', $this->base.$path);
            self::assertContains($r->getStatusCode(), [404], $path);
            self::assertArrayNotHasKey('x-amz-request-id', $r->getHeaders(false), $path);
        }
    }

    public function testMediaLocationCannotEscapeThePublicBucket(): void
    {
        foreach ([
            '/media/..%2fwave-private%2faudio%2fx.m4a',
            '/media/%2e%2e/wave-private/audio/x.m4a',
            '/media/..%252fwave-private/audio/x.m4a',
            '/media/?list-type=2',
            '/media/x?acl',
            '/media/x?response-content-type=text/html',
        ] as $path) {
            $r = $this->http->request('GET', $this->base.$path);
            $headers = $r->getHeaders(false);
            self::assertArrayNotHasKey('x-amz-request-id', $headers, $path);
            self::assertStringNotContainsString('ListBucketResult', $r->getContent(false), $path);
            self::assertStringNotContainsString('audio/', $headers['content-type'][0] ?? '', $path);
        }
        self::assertContains($this->http->request('PUT', $this->base.'/media/x.webp', ['body' => 'x'])->getStatusCode(), [403, 404]);
        self::assertContains($this->http->request('DELETE', $this->base.'/media/x.webp')->getStatusCode(), [403, 404]);
    }

    public function testApiSecurityHeadersAndNoFingerprinting(): void
    {
        $r = $this->http->request('GET', $this->base.'/api/v1/genres');
        $h = $r->getHeaders(false);
        self::assertSame(['nosniff'], array_values(array_unique($h['x-content-type-options'] ?? [])));
        self::assertSame('no-referrer', $h['referrer-policy'][0] ?? null);
        // nginx and API Platform both emit it (duplicate but consistent).
        self::assertSame(['deny'], array_values(array_unique(array_map('strtolower', $h['x-frame-options'] ?? []))));
        self::assertStringContainsString("default-src 'none'", $h['content-security-policy'][0] ?? '');
        self::assertArrayNotHasKey('x-powered-by', $h);
        self::assertSame('nginx', $h['server'][0] ?? 'nginx', 'server_tokens off: no version');
    }

    public function testBodyLimits(): void
    {
        $r = $this->http->request('POST', $this->base.'/api/v1/auth/login', ['body' => str_repeat('a', 1_100_000), 'headers' => ['Content-Type' => 'application/json']]);
        self::assertSame(413, $r->getStatusCode(), '1 MB default');
        $r = $this->http->request('POST', $this->base.'/api/v1/me/avatar', ['body' => str_repeat('a', 6_400_000), 'headers' => ['Content-Type' => 'multipart/form-data; boundary=x']]);
        self::assertSame(413, $r->getStatusCode(), '6 MB on avatar');
    }

    public function testStreamLocationOnlyAcceptsGetAndHead(): void
    {
        $r = $this->http->request('POST', $this->base.'/api/v1/stream/abc.def');
        self::assertSame(403, $r->getStatusCode());
    }

    public function testPhpFilesAreNeverExecutedFromThePath(): void
    {
        foreach (['/index.php', '/api/v1/../index.php', '/api/v1/x.php', '/api/v1/genres/..%2f..%2fbin%2fconsole'] as $path) {
            $r = $this->http->request('GET', $this->base.$path);
            self::assertStringNotContainsString('<?php', $r->getContent(false), $path);
        }
    }
}
