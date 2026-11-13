<?php

declare(strict_types=1);

namespace App\Activity;

use App\Entity\SecurityAlert;
use App\Enum\AlertRule;
use App\Enum\AlertSeverity;
use App\Enum\AlertStatus;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /admin/alerts` read side (DATA_MODEL §16): keyset on (severity rank DESC, last_seen_at
 * DESC, id DESC), index (status, severity, last_seen_at DESC).
 */
final readonly class AlertReader
{
    private const string RANK = "CASE severity WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END";

    public function __construct(private EntityManagerInterface $em)
    {
    }

    /**
     * @param array{int, \DateTimeImmutable, Uuid}|null $after
     *
     * @return list<SecurityAlert>
     */
    public function page(AlertStatus $status, ?AlertSeverity $severity, ?AlertRule $rule, ?array $after, int $limit): array
    {
        $where = ['status = :status'];
        $params = ['status' => $status->value, 'n' => $limit];
        if (null !== $severity) {
            $where[] = 'severity = :severity';
            $params['severity'] = $severity->value;
        }
        if (null !== $rule) {
            $where[] = 'rule = :rule';
            $params['rule'] = $rule->value;
        }
        if (null !== $after) {
            $where[] = '('.self::RANK.', last_seen_at, id) < (:r, :t, :id)';
            $params['r'] = $after[0];
            $params['t'] = $after[1]->format('Y-m-d H:i:s.uP');
            $params['id'] = $after[2]->toRfc4122();
        }
        /** @var list<string> $ids */
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM security_alert WHERE '.implode(' AND ', $where).' ORDER BY '.self::RANK.' DESC, last_seen_at DESC, id DESC LIMIT :n',
            $params,
        );
        if ([] === $ids) {
            return [];
        }
        /** @var list<SecurityAlert> $alerts */
        $alerts = $this->em->createQueryBuilder()
            ->select('a', 'u', 'b')
            ->from(SecurityAlert::class, 'a')
            ->leftJoin('a.subjectUser', 'u')
            ->leftJoin('a.acknowledgedBy', 'b')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', $ids, ArrayParameterType::STRING)
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($alerts as $alert) {
            $byId[$alert->getId()->toRfc4122()] = $alert;
        }

        return array_values(array_filter(array_map(static fn (string $id): ?SecurityAlert => $byId[$id] ?? null, $ids)));
    }
}
