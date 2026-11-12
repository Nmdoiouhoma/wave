<?php

declare(strict_types=1);

namespace App\Problem;

use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class ProblemResponseFactory
{
    /**
     * @param list<Violation>       $violations
     * @param array<string, string> $headers
     */
    public function create(ProblemCode $code, ?string $detail = null, array $violations = [], array $headers = []): JsonResponse
    {
        $body = [
            'type' => 'urn:wave:problem:'.$code->value,
            'title' => $code->title(),
            'status' => $code->status(),
            'code' => $code->value,
        ];
        if (null !== $detail && '' !== $detail) {
            $body['detail'] = $detail;
        }
        if (ProblemCode::ValidationFailed === $code) {
            $body['violations'] = array_map(
                static fn (Violation $v): array => ['propertyPath' => $v->propertyPath, 'message' => $v->message, 'code' => $v->code],
                $violations,
            );
        }

        // Invalid UTF-8 in any echoed value is substituted (U+FFFD): a problem response is
        // always encodable, never turned into a 500 by the listener itself (W-37 F19).
        $response = new JsonResponse(null, $code->status(), $headers);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_INVALID_UTF8_SUBSTITUTE);
        $response->setData($body);
        $response->headers->set('Content-Type', 'application/problem+json');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function fromException(ApiProblemException $e): JsonResponse
    {
        return $this->create($e->problemCode, $e->detail, $e->violations, $e->headers);
    }
}
