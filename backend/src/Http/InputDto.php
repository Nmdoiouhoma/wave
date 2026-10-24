<?php

declare(strict_types=1);

namespace App\Http;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Problem\Violation;
use Symfony\Component\Validator\Constraints\NotNull;

/** Base of input DTOs decoded by {@see JsonInputDecoder}. */
abstract class InputDto
{
    /** @var array<string, true> keys present in the request body */
    public array $provided = [];

    final public function has(string $property): bool
    {
        return isset($this->provided[$property]);
    }

    /**
     * PATCH fields typed `string` in the contract may be omitted but not set to null.
     *
     * @throws ApiProblemException validation_failed
     */
    final public function rejectExplicitNull(string ...$properties): void
    {
        $violations = [];
        foreach ($properties as $property) {
            if ($this->has($property) && null === $this->{$property}) {
                $violations[] = new Violation($property, 'This value should not be null.', NotNull::IS_NULL_ERROR);
            }
        }
        if ([] !== $violations) {
            throw new ApiProblemException(ProblemCode::ValidationFailed, 'Validation failed.', $violations);
        }
    }
}
