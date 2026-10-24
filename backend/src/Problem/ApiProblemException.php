<?php

declare(strict_types=1);

namespace App\Problem;

use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Business error rendered as RFC 7807 by {@see ProblemExceptionListener}; status derives from the code.
 */
final class ApiProblemException extends \RuntimeException
{
    /**
     * @param list<Violation>       $violations
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly ProblemCode $problemCode,
        public readonly ?string $detail = null,
        public readonly array $violations = [],
        public readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($detail ?? $problemCode->title(), $problemCode->status(), $previous);
    }

    public static function notFound(?string $detail = null): self
    {
        return new self(ProblemCode::NotFound, $detail);
    }

    public static function forbidden(?string $detail = null): self
    {
        return new self(ProblemCode::Forbidden, $detail);
    }

    public static function validation(string $propertyPath, string $message, ?string $code = null): self
    {
        return new self(ProblemCode::ValidationFailed, 'Validation failed.', [new Violation($propertyPath, $message, $code)]);
    }

    public static function fromViolations(ConstraintViolationListInterface $list): self
    {
        $violations = [];
        foreach ($list as $violation) {
            $violations[] = new Violation($violation->getPropertyPath(), (string) $violation->getMessage(), $violation->getCode());
        }

        return new self(ProblemCode::ValidationFailed, 'Validation failed.', $violations);
    }
}
