<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Activity\AdminQuery;
use App\ApiResource\AnnouncementPage;
use App\Entity\Announcement;
use App\Mapper\JournalMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /admin/announcements` (ROLE_ADMIN): every announcement, newest `startsAt` first. Keyset
 * (starts_at DESC, id DESC); the table stays small (a handful per month).
 *
 * @implements ProviderInterface<AnnouncementPage>
 */
final readonly class AdminAnnouncementListProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private EntityManagerInterface $em,
        private JournalMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AnnouncementPage
    {
        AdminQuery::requireAdmin($this->currentUser->require());
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec);

        $qb = $this->em->createQueryBuilder()
            ->select('a')
            ->from(Announcement::class, 'a')
            ->orderBy('a.startsAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($page->limit + 1);
        if (null !== $page->after) {
            [$at, $id] = Keyset::timestampAndId($page->after);
            $qb->where('a.startsAt < :at OR (a.startsAt = :at AND a.id < :id)')
                ->setParameter('at', $at)
                ->setParameter('id', $id->toRfc4122());
        }
        /** @var list<Announcement> $rows */
        $rows = $qb->getQuery()->getResult();
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $last = $rows[\count($rows) - 1];
            $next = $this->codec->encode(Keyset::fromTimestampAndId($last->getStartsAt(), $last->getId()));
        }

        return new AnnouncementPage(array_map($this->mapper->announcement(...), $rows), $next);
    }
}
