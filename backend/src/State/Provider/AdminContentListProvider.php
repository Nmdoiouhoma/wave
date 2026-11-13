<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Admin\AdminContentQuery;
use App\Admin\AdminQueryParams;
use App\ApiResource\AdminContentItemView;
use App\ApiResource\AdminContentPage;
use App\Entity\User;
use App\Enum\Moderation;
use App\Enum\ReportTargetType;
use App\Mapper\DateFormat;
use App\Mapper\ReportMapper;
use App\Mapper\UserMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Repository\UserRepository;
use App\Storage\ObjectStorage;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /admin/content` (ROLE_MODERATOR, FB-28). `type` is required (absent → 422
 * `validation_failed`); an unknown `type`/`moderation`, a malformed `ownerId` or a `q` outside
 * 2-100 characters → 400 `malformed_request`. Moderators see everything, hidden included.
 *
 * @implements ProviderInterface<AdminContentPage>
 */
final readonly class AdminContentListProvider implements ProviderInterface
{
    public function __construct(
        private AdminContentQuery $query,
        private UserRepository $users,
        private UserMapper $userMapper,
        private ObjectStorage $storage,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminContentPage
    {
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $type = AdminQueryParams::choice($request, 'type', AdminContentQuery::TYPES)
            ?? throw ApiProblemException::validation('type', 'This value should not be blank.');
        $moderation = AdminQueryParams::choice($request, 'moderation', array_map(static fn (Moderation $m): string => $m->value, Moderation::cases()));
        $ownerId = AdminQueryParams::uuid($request, 'ownerId');
        $q = AdminQueryParams::text($request, 'q', 2, 100);
        $page = PageRequest::fromRequest($request, $this->codec);
        $after = null === $page->after ? null : Keyset::timestampAndId($page->after);

        $rows = $this->query->page($type, $moderation, $ownerId, $q, $after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId(new \DateTimeImmutable($last['created_at']), Uuid::fromString($last['id'])));
        }

        $owners = [];
        foreach ($this->users->findByIdsOrdered(array_values(array_unique(array_column($rows, 'owner_id')))) as $owner) {
            $owners[$owner->getId()->toRfc4122()] = $owner;
        }
        $reportType = ReportTargetType::from($type);
        $reports = $this->query->openReportCounts($reportType, array_column($rows, 'id'));

        $items = [];
        foreach ($rows as $row) {
            $owner = $owners[$row['owner_id']] ?? null;
            if (!$owner instanceof User) {
                continue; // owner deleted between the two queries: the item went with it
            }
            $items[] = new AdminContentItemView(
                $type,
                $row['id'],
                mb_substr($row['label'], 0, ReportMapper::LABEL_MAX),
                null === $row['image_key'] ? null : $this->storage->publicUrl($row['image_key']),
                $this->userMapper->toSummary($owner),
                $row['moderation'],
                $row['status'],
                $row['visibility'],
                $reports[$row['id']] ?? 0,
                DateFormat::format(new \DateTimeImmutable($row['created_at'])),
            );
        }

        return new AdminContentPage($items, $next);
    }
}
