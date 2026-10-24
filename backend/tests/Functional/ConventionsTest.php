<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** W-03: JSON only under /api/v1, problem+json for every error. */
final class ConventionsTest extends WaveApiTestCase
{
    public function testUnknownRouteIsProblemNotFound(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/nope'), 404, 'not_found');
        $this->assertProblem($this->api('GET', '/somewhere-else'), 404, 'not_found');
    }

    public function testProtectedRouteWithoutTokenIsUnauthenticated(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/me'), 401, 'unauthenticated');
    }

    public function testGarbageBearerIsUnauthenticated(): void
    {
        $this->assertProblem($this->api('GET', '/api/v1/me', null, ['headers' => ['Authorization' => 'Bearer nope']]), 401, 'unauthenticated');
    }

    public function testSecurityHeadersAreLeftToNginx(): void
    {
        $headers = $this->api('GET', '/api/v1/genres')->getHeaders();

        self::assertArrayNotHasKey('x-frame-options', $headers);
        self::assertArrayNotHasKey('x-content-type-options', $headers);
    }

    public function testSameOriginPreflightIsAnswered(): void
    {
        $response = $this->client->request('OPTIONS', 'http://localhost/api/v1/me', ['headers' => [
            'Origin' => 'http://localhost', 'Access-Control-Request-Method' => 'PATCH', 'Access-Control-Request-Headers' => 'content-type, authorization',
        ]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('http://localhost', $response->getHeaders()['access-control-allow-origin'][0] ?? null);
    }

    public function testGenresArePublicPlainJsonAndCacheable(): void
    {
        $response = $this->api('GET', '/api/v1/genres');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaders()['content-type'][0]);
        $genres = $this->json($response);
        self::assertCount(25, $genres);
        self::assertSame(['id' => 'pop', 'name' => 'Pop'], $genres[0]);
        self::assertSame(['id' => 'other', 'name' => 'Other'], $genres[24]);
        $cacheControl = $response->getHeaders()['cache-control'][0];
        self::assertStringContainsString('public', $cacheControl);
        self::assertStringContainsString('max-age=86400', $cacheControl);
    }

    public function testNoHydraEvenWhenAskedForJsonLd(): void
    {
        $response = $this->api('GET', '/api/v1/genres', null, ['headers' => ['Accept' => 'application/ld+json']]);

        self::assertStringNotContainsString('@context', $response->getContent(false));
    }

    public function testApiDocsAreDisabledOutsideDev(): void
    {
        foreach (['/api/v1/docs', '/api/v1/docs.json', '/api/v1/docs.jsonopenapi', '/api/v1/docs.html'] as $url) {
            foreach (['application/json', 'text/html', 'application/vnd.openapi+json'] as $accept) {
                self::assertSame(404, $this->api('GET', $url, null, ['headers' => ['Accept' => $accept]])->getStatusCode(), $url.' '.$accept);
            }
        }
    }
}
