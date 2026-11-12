<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ReportTargetPage;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Mapper\ReportMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\ReportRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /admin/report-targets` (ROLE_MODERATOR): targets grouped, most reported first then oldest
 * first, keyset on (count DESC, firstReportedAt ASC, targetType, targetId). `status` (default
 * open) and `targetType` filters; an unknown value is 400 `malformed_request`.
 *
 * @implements ProviderInterface<ReportTargetPage>
 */
final readonly class ReportTargetListProvider implements ProviderInterface
{
    public function __construct(
        private ReportRepository $reports,
        private ReportMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportTargetPage
    {
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $status = self::enumQuery($request, 'status', ReportStatus::class) ?? ReportStatus::Open;
        $type = self::enumQuery($request, 'targetType', ReportTargetType::class);
        $page = PageRequest::fromRequest($request, $this->codec);

        $rows = $this->reports->findTargetPage($status, $type, self::after($page->after), $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode([$last['n'], ...Keyset::fromTimestampAndId($last['first_at'], Uuid::fromString($last['target_id'])), $last['target_type']]);
        }

        return new ReportTargetPage($this->mapper->toTargetViews($rows, $status), $next);
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|null
     */
    private static function enumQuery(?Request $request, string $name, string $enum): ?\BackedEnum
    {
        if (null === $request || !$request->query->has($name)) {
            return null;
        }
        $raw = $request->query->all()[$name];
        $value = \is_string($raw) ? $enum::tryFrom($raw) : null;

        return $value ?? throw new ApiProblemException(ProblemCode::MalformedRequest, $name.' is not a valid value.');
    }

    /**
     * Cursor keys: [count, firstReportedAt, targetId, targetType].
     *
     * @param list<string|int|float|bool|null>|null $keys
     *
     * @return array{int, \DateTimeImmutable, string, Uuid}|null
     */
    private static function after(?array $keys): ?array
    {
        if (null === $keys) {
            return null;
        }
        if (4 !== \count($keys) || !\is_int($keys[0]) || !\is_string($keys[3]) || null === ReportTargetType::tryFrom($keys[3])) {
            throw CursorCodec::invalid();
        }
        [$at, $id] = Keyset::timestampAndId([$keys[1], $keys[2]]);

        return [$keys[0], $at, $keys[3], $id];
    }
}
