<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\ActiveAnnouncementsView;
use App\Entity\Announcement;
use App\Mapper\JournalMapper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * `GET /announcements` (any signed-in user): at most 3 active announcements
 * (`startsAt <= now < endsAt`, open-ended when `endsAt` is null), `warning` first then newest.
 *
 * @implements ProviderInterface<ActiveAnnouncementsView>
 */
final readonly class ActiveAnnouncementsProvider implements ProviderInterface
{
    public const int MAX = 3;

    public function __construct(
        private EntityManagerInterface $em,
        private JournalMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ActiveAnnouncementsView
    {
        /** @var list<Announcement> $rows */
        $rows = $this->em->createQueryBuilder()
            ->select('a', "CASE WHEN a.level = 'warning' THEN 0 ELSE 1 END AS HIDDEN levelRank")
            ->from(Announcement::class, 'a')
            ->where('a.startsAt <= :now')
            ->andWhere('a.endsAt IS NULL OR a.endsAt > :now')
            ->setParameter('now', $this->clock->now())
            ->orderBy('levelRank', 'ASC')
            ->addOrderBy('a.startsAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults(self::MAX)
            ->getQuery()
            ->getResult();

        return new ActiveAnnouncementsView(array_map($this->mapper->announcement(...), $rows));
    }
}
