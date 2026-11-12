<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ReportPage;
use App\Mapper\ReportMapper;
use App\Moderation\ReportTargetResolver;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Repository\ReportRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /admin/report-targets/{targetType}/{targetId}/reports` (ROLE_MODERATOR): every report of
 * the target, newest first. Unknown target (no such row and no report) → 404.
 *
 * @implements ProviderInterface<ReportPage>
 */
final readonly class TargetReportsProvider implements ProviderInterface
{
    public function __construct(
        private ReportTargetResolver $targets,
        private ReportRepository $reports,
        private ReportMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportPage
    {
        [$type, $id] = ReportTargetResolver::fromPath($uriVariables['targetType'] ?? null, $uriVariables['targetId'] ?? null);
        if (null === $this->targets->find($type, $id) && !$this->reports->existsForTarget($type, $id)) {
            throw ApiProblemException::notFound();
        }
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);

        $rows = $this->reports->findPageForTarget($type, $id, $page->after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getCreatedAt(), $last->getId()));
        }

        return new ReportPage(array_map($this->mapper->toView(...), $rows), $next);
    }
}
