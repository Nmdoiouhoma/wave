<?php

declare(strict_types=1);

namespace App\Admin;

use App\Enum\ReportTargetType;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Read side of `GET /admin/content` (FB-28): one content type per call, hidden, private,
 * processing and failed items included, newest first (keyset on `(created_at, id)` DESC).
 *
 * @phpstan-type ContentRow array{id: string, created_at: string, label: string, image_key: ?string, owner_id: string, moderation: string, status: ?string, visibility: ?string}
 */
final readonly class AdminContentQuery
{
    public const array TYPES = ['track', 'post', 'comment', 'playlist', 'artist'];

    /** Per type: FROM clause, label expression, image key, owner, status and visibility expressions. */
    private const array SQL = [
        'track' => ['track x', 'x.title', 'x.cover_key', 'x.owner_id', 'x.status', 'x.visibility'],
        'post' => [
            'post x JOIN track t ON t.id = x.track_id',
            'coalesce(x.caption, t.title)',
            "CASE WHEN x.visual_type = 'image' THEN x.visual_key ELSE x.visual_poster_key END",
            'x.author_id',
            'x.status',
            'NULL',
        ],
        'comment' => ['comment x', 'x.body', 'NULL', 'x.author_id', 'NULL', 'NULL'],
        'playlist' => ['playlist x', 'x.name', 'NULL', 'x.owner_id', 'NULL', "CASE WHEN x.is_public THEN 'public' ELSE 'private' END"],
        'artist' => ['artist x', 'x.name', 'x.avatar_key', 'x.owner_id', 'NULL', 'NULL'],
    ];

    public function __construct(private Connection $db)
    {
    }

    /**
     * One page (limit + 1 rows to detect the next one). `q` (≥ 2 chars) matches the label by
     * substring or trigram similarity, accents and case folded.
     *
     * @param array{\DateTimeImmutable, Uuid}|null $after
     *
     * @return list<ContentRow>
     */
    public function page(string $type, ?string $moderation, ?Uuid $ownerId, ?string $q, ?array $after, int $limit): array
    {
        [$from, $label, $image, $owner, $status, $visibility] = self::SQL[$type];
        $where = ['TRUE'];
        $params = ['limit' => $limit + 1];
        $cte = '';
        if (null !== $moderation) {
            $where[] = 'x.moderation = :moderation';
            $params['moderation'] = $moderation;
        }
        if (null !== $ownerId) {
            $where[] = $owner.' = :owner';
            $params['owner'] = $ownerId->toRfc4122();
        }
        if (null !== $q) {
            $cte = "WITH n AS (SELECT wave_unaccent(lower(:q)) AS q),
                q AS (SELECT n.q, replace(replace(replace(n.q, chr(92), chr(92) || chr(92)), '%', chr(92) || '%'), '_', chr(92) || '_') AS p FROM n) ";
            $from .= ' CROSS JOIN q';
            $where[] = "(wave_unaccent(lower({$label})) LIKE '%' || q.p || '%' OR wave_unaccent(lower({$label})) % q.q)";
            $params['q'] = $q;
        }
        if (null !== $after) {
            $where[] = '(x.created_at, x.id) < (:at, :after)';
            $params['at'] = $after[0]->format('Y-m-d H:i:s.uP');
            $params['after'] = $after[1]->toRfc4122();
        }

        /** @var list<ContentRow> $rows */
        $rows = $this->db->fetchAllAssociative(
            $cte."SELECT x.id, x.created_at, {$label} AS label, {$image} AS image_key, {$owner} AS owner_id,
                    x.moderation, {$status} AS status, {$visibility} AS visibility
             FROM {$from} WHERE ".implode(' AND ', $where).' ORDER BY x.created_at DESC, x.id DESC LIMIT :limit',
            $params,
        );

        return $rows;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, int> id => open report count
     */
    public function openReportCounts(ReportTargetType $type, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $out = [];
        foreach ($this->db->fetchAllKeyValue(
            "SELECT target_id, count(*) FROM report WHERE status = 'open' AND target_type = :t AND target_id IN (:ids) GROUP BY target_id",
            ['t' => $type->value, 'ids' => $ids],
            ['ids' => ArrayParameterType::STRING],
        ) as $id => $n) {
            $out[(string) $id] = (int) $n;
        }

        return $out;
    }
}
