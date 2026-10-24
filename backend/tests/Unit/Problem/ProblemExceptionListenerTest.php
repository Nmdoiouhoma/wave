<?php

declare(strict_types=1);

namespace App\Tests\Unit\Problem;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Problem\ProblemExceptionListener;
use App\Problem\ProblemResponseFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ProblemExceptionListenerTest extends TestCase
{
    public function testUnexpectedErrorLeaksNothingWithoutDebug(): void
    {
        $body = $this->render(new \RuntimeException('SQLSTATE secret at /app/src/Foo.php:12'), debug: false);

        self::assertSame(500, $body['status']);
        self::assertSame('internal_error', $body['code']);
        self::assertArrayNotHasKey('detail', $body);
        self::assertStringNotContainsString('SQLSTATE', (string) json_encode($body));
    }

    public function testBusinessErrorCarriesItsStatus(): void
    {
        $body = $this->render(new ApiProblemException(ProblemCode::ArtistHasTracks, 'Delete the tracks first.'), debug: false);

        self::assertSame(['type' => 'urn:wave:problem:artist_has_tracks', 'title' => 'Artist has tracks', 'status' => 409, 'code' => 'artist_has_tracks', 'detail' => 'Delete the tracks first.'], $body);
    }

    public function testEveryCodeHasAStatusAndTitle(): void
    {
        foreach (ProblemCode::cases() as $code) {
            self::assertGreaterThanOrEqual(400, $code->status());
            self::assertNotSame('', $code->title());
        }
    }

    /** @return array<string, mixed> */
    private function render(\Throwable $e, bool $debug): array
    {
        $listener = new ProblemExceptionListener(new ProblemResponseFactory(), new NullLogger(), $debug);
        $event = new ExceptionEvent($this->createStub(HttpKernelInterface::class), Request::create('/api/v1/x'), HttpKernelInterface::MAIN_REQUEST, $e);
        $listener($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame('application/problem+json', $response->headers->get('Content-Type'));

        /* @var array<string, mixed> */
        return json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }
}
