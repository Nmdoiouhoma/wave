<?php

declare(strict_types=1);

namespace App\Pagination;

use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use Symfony\Component\HttpFoundation\Request;

/** Parsed `?cursor=&limit=` (limit default 20, max 50). */
final readonly class PageRequest
{
    public const int DEFAULT_LIMIT = 20;
    public const int MAX_LIMIT = 50;

    /** @param list<string|int|float|bool|null>|null $after keyset of the last item of the previous page */
    public function __construct(
        public int $limit,
        public ?array $after,
    ) {
    }

    public static function fromRequest(?Request $request, CursorCodec $codec, int $defaultLimit = self::DEFAULT_LIMIT, int $maxLimit = self::MAX_LIMIT): self
    {
        $limitRaw = $request?->query->getString('limit') ?? '';
        $limit = $defaultLimit;
        if ('' !== $limitRaw) {
            if (1 !== preg_match('/^[0-9]{1,3}$/', $limitRaw) || (int) $limitRaw < 1 || (int) $limitRaw > $maxLimit) {
                throw new ApiProblemException(ProblemCode::MalformedRequest, 'limit must be an integer between 1 and '.$maxLimit.'.');
            }
            $limit = (int) $limitRaw;
        }
        $cursor = $request?->query->getString('cursor') ?? '';
        $after = '' === $cursor ? null : $codec->decode($cursor);

        return new self($limit, $after);
    }
}
