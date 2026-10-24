<?php

declare(strict_types=1);

namespace App\Http;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Problem\Violation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Type;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Single JSON decoding path for every write endpoint (API Platform processors and auth
 * controllers), so errors are identical everywhere:
 * - not JSON content type → 415 unsupported_media_type
 * - empty/invalid JSON or not an object → 400 malformed_request
 * - unknown property or wrong JSON type → 422 validation_failed (one violation each)
 * - Validator constraints of the input DTO → 422 validation_failed.
 *
 * Input DTOs are classes with public typed properties and defaults; properties present in
 * the body are listed in {@see InputDto::$provided} (merge-patch semantics).
 */
final readonly class JsonInputDecoder
{
    private const array JSON_TYPES = ['application/json', 'application/merge-patch+json'];

    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @template T of InputDto
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function decode(Request $request, string $class, bool $allowEmptyBody = false): InputDto
    {
        $content = trim($request->getContent());
        if ('' === $content) {
            if (!$allowEmptyBody) {
                throw new ApiProblemException(ProblemCode::MalformedRequest, 'A JSON body is required.');
            }
            $data = [];
        } else {
            $mime = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
            if (!\in_array($mime, self::JSON_TYPES, true)) {
                throw new ApiProblemException(ProblemCode::UnsupportedMediaType, 'Send application/json.');
            }
            try {
                $data = json_decode($content, true, 32, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
            } catch (\JsonException) {
                throw new ApiProblemException(ProblemCode::MalformedRequest, 'The body is not valid JSON.');
            }
            if (!\is_array($data) || (array_is_list($data) && [] !== $data)) {
                throw new ApiProblemException(ProblemCode::MalformedRequest, 'The body must be a JSON object.');
            }
        }

        return $this->hydrate($data, $class);
    }

    /**
     * @template T of InputDto
     *
     * @param array<mixed>    $data
     * @param class-string<T> $class
     *
     * @return T
     */
    public function hydrate(array $data, string $class): InputDto
    {
        $dto = new $class();
        $reflection = new \ReflectionClass($class);
        $violations = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            if ('provided' === $key || !$reflection->hasProperty($key) || !$reflection->getProperty($key)->isPublic() || $reflection->getProperty($key)->isStatic()) {
                $violations[] = new Violation($key, 'This field is not allowed.', Collection::NO_SUCH_FIELD_ERROR);
                continue;
            }
            $property = $reflection->getProperty($key);
            $type = $property->getType();
            [$ok, $converted] = $this->convert($value, $type);
            if (!$ok) {
                $violations[] = new Violation($key, 'This value should be of type '.$this->describe($type).'.', Type::INVALID_TYPE_ERROR);
                continue;
            }
            $property->setValue($dto, $converted);
            $dto->provided[$key] = true;
        }

        if ([] !== $violations) {
            throw new ApiProblemException(ProblemCode::ValidationFailed, 'Validation failed.', $violations);
        }

        $list = $this->validator->validate($dto);
        if (\count($list) > 0) {
            throw ApiProblemException::fromViolations($list);
        }

        return $dto;
    }

    /** @return array{bool, mixed} [accepted, converted value] */
    private function convert(mixed $value, ?\ReflectionType $type): array
    {
        if (!$type instanceof \ReflectionNamedType) {
            return [true, $value];
        }
        if (null === $value) {
            return [$type->allowsNull(), null];
        }
        $name = $type->getName();
        $result = match ($name) {
            'string' => \is_string($value) ? $value : null,
            'int' => \is_int($value) ? $value : null,
            'float' => \is_int($value) || \is_float($value) ? (float) $value : null,
            'bool' => \is_bool($value) ? $value : null,
            'array' => \is_array($value) ? $value : null,
            'mixed' => $value,
            default => enum_exists($name) && is_subclass_of($name, \BackedEnum::class) && (\is_string($value) || \is_int($value))
                ? $name::tryFrom($value)
                : null,
        };

        return [null !== $result, $result];
    }

    private function describe(?\ReflectionType $type): string
    {
        if (!$type instanceof \ReflectionNamedType) {
            return 'mixed';
        }
        $name = $type->getName();
        if (enum_exists($name) && is_subclass_of($name, \BackedEnum::class)) {
            return 'one of '.implode(', ', array_map(static fn (\BackedEnum $c): string => (string) $c->value, $name::cases()));
        }

        return $name;
    }
}
