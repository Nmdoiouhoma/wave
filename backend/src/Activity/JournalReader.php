<?php

declare(strict_types=1);

namespace App\Activity;

use App\Entity\ActivityEvent;
use App\Enum\ActivityCategory;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Read side of the journal (DATA_MODEL §15) for the ROLE_ADMIN pages. Keyset on `id` DESC
 * (UUID v7 = time order). Each query is shaped for one index of §15 / Version20261113000400;
 * EXPLAIN on 100 k events in docs/handoff/w72-backend.md.
 */
final readonly class JournalReader
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * Events whose subject or actor is the user: three index range scans, each stopped at
     * `limit` rows, merged. (1) subject = user; (2) admin events by the user (staff actor);
     * (3) other events by the user about someone else (e.g. reports they filed).
     *
     * @return list<ActivityEvent> up to $limit rows, newest first
     */
    public function userPage(Uuid $user, ?ActivityCategory $category, ?Uuid $after, int $limit): array
    {
        $params = ['u' => $user->toRfc4122(), 'n' => $limit];
        $common = '';
        if (null !== $after) {
            $common .= ' AND id < :after';
            $params['after'] = $after->toRfc4122();
        }
        $byCategory = '';
        if (null !== $category) {
            $byCategory = ' AND category = :c';
            $params['c'] = $category->value;
        }
        $branches = ['(SELECT id FROM activity_event WHERE subject_user_id = :u'.$byCategory.$common.' ORDER BY id DESC LIMIT :n)'];
        if (null === $category || ActivityCategory::Admin === $category) {
            $branches[] = "(SELECT id FROM activity_event WHERE actor_id = :u AND category = 'admin'".$common.' ORDER BY id DESC LIMIT :n)';
        }
        if (ActivityCategory::Admin !== $category) {
            $branches[] = "(SELECT id FROM activity_event WHERE actor_id = :u AND category <> 'admin' AND actor_id IS DISTINCT FROM subject_user_id".$byCategory.$common.' ORDER BY id DESC LIMIT :n)';
        }

        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM ('.implode(' UNION ', $branches).') AS t ORDER BY id DESC LIMIT :n',
            $params,
        );

        return $this->load($ids);
    }

    /**
     * Every `admin.*` event, optionally by one staff member (console events have no actor).
     *
     * @return list<ActivityEvent> up to $limit rows, newest first
     */
    public function auditPage(?Uuid $actor, ?Uuid $after, int $limit): array
    {
        $params = ['n' => $limit];
        $sql = "SELECT id FROM activity_event WHERE category = 'admin'";
        if (null !== $actor) {
            $sql .= ' AND actor_id = :a';
            $params['a'] = $actor->toRfc4122();
        }
        if (null !== $after) {
            $sql .= ' AND id < :after';
            $params['after'] = $after->toRfc4122();
        }
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn($sql.' ORDER BY id DESC LIMIT :n', $params);

        return $this->load($ids);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<ActivityEvent> in the order of $ids
     */
    private function load(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        /** @var list<ActivityEvent> $events */
        $events = $this->em->createQueryBuilder()
            ->select('e', 'a', 's')
            ->from(ActivityEvent::class, 'e')
            ->leftJoin('e.actor', 'a')
            ->leftJoin('e.subjectUser', 's')
            ->where('e.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($events as $event) {
            $byId[$event->getId()->toRfc4122()] = $event;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?ActivityEvent => $byId[$id] ?? null, $ids)));
    }
}
