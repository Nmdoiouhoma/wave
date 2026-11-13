<?php

declare(strict_types=1);

namespace App\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Read side of `/admin/users` (FB-28, ADR 013): every account, suspended and unverified included.
 * Plain SQL; the list is keyset on `id` DESC (UUID v7 = creation order).
 */
final readonly class AdminUserQuery
{
    public const string ROLE_USER = 'ROLE_USER';
    public const string ROLE_MODERATOR = 'ROLE_MODERATOR';
    public const string ROLE_ADMIN = 'ROLE_ADMIN';
    public const array ROLES = [self::ROLE_USER, self::ROLE_MODERATOR, self::ROLE_ADMIN];
    public const array STATUSES = ['active', 'suspended', 'unverified'];

    public const string HAS_ADMIN = "u.roles::jsonb @> '[\"ROLE_ADMIN\"]'";
    public const string HAS_MODERATOR = "u.roles::jsonb @> '[\"ROLE_MODERATOR\"]'";

    /** Same matching as `/search?type=users` (W-27), without its exclusions. */
    private const string NAME_MATCH = <<<'SQL'
        (wave_unaccent(lower(u.username::text)) LIKE q.p || '%'
            OR wave_unaccent(lower(u.display_name)) LIKE q.p || '%'
            OR wave_unaccent(lower(u.display_name)) LIKE '% ' || q.p || '%'
            OR wave_unaccent(lower(u.username::text)) % q.q
            OR wave_unaccent(lower(u.display_name)) % q.q)
        SQL;

    public function __construct(private Connection $db)
    {
    }

    /**
     * Ids of one page (limit + 1 rows to detect the next page).
     *
     * `status`: `suspended` = suspended_at set; `unverified` = not suspended and email not verified;
     * `active` = neither (the three filters partition the accounts).
     *
     * @return list<string>
     */
    public function pageIds(?string $q, bool $emailSearch, ?string $role, ?string $status, ?Uuid $after, int $limit): array
    {
        $where = ['TRUE'];
        $params = ['limit' => $limit + 1];
        $cte = '';
        $from = 'app_user u';
        if (null !== $q) {
            if ($emailSearch) {
                // citext: exact, case-insensitive (admins only, decided by the caller).
                $where[] = 'u.email = :q';
                $params['q'] = $q;
            } else {
                $cte = "WITH n AS (SELECT wave_unaccent(lower(:q)) AS q),
                    q AS (SELECT n.q, replace(replace(replace(n.q, chr(92), chr(92) || chr(92)), '%', chr(92) || '%'), '_', chr(92) || '_') AS p FROM n) ";
                $from .= ', q';
                $where[] = self::NAME_MATCH;
                $params['q'] = $q;
            }
        }
        $where[] = match ($role) {
            // `@>` rather than `?`: DBAL would read `?` as a positional placeholder.
            self::ROLE_ADMIN => self::HAS_ADMIN,
            self::ROLE_MODERATOR => self::HAS_MODERATOR.' AND NOT '.self::HAS_ADMIN,
            self::ROLE_USER => 'NOT '.self::HAS_MODERATOR.' AND NOT '.self::HAS_ADMIN,
            default => 'TRUE',
        };
        $where[] = match ($status) {
            'suspended' => 'u.suspended_at IS NOT NULL',
            'unverified' => 'u.suspended_at IS NULL AND u.email_verified_at IS NULL',
            'active' => 'u.suspended_at IS NULL AND u.email_verified_at IS NOT NULL',
            default => 'TRUE',
        };
        if (null !== $after) {
            $where[] = 'u.id < :after';
            $params['after'] = $after->toRfc4122();
        }

        /* @var list<string> */
        return $this->db->fetchFirstColumn(
            $cte.'SELECT u.id FROM '.$from.' WHERE '.implode(' AND ', $where).' ORDER BY u.id DESC LIMIT :limit',
            $params,
        );
    }

    /** @return array{tracks: int, posts: int, comments: int, playlists: int, artists: int, reportsFiled: int} */
    public function counts(Uuid $userId): array
    {
        /** @var array<string, int|string> $row */
        $row = $this->db->fetchAssociative(
            'SELECT
                (SELECT count(*) FROM track WHERE owner_id = :u) AS tracks,
                (SELECT count(*) FROM post WHERE author_id = :u) AS posts,
                (SELECT count(*) FROM comment WHERE author_id = :u) AS comments,
                (SELECT count(*) FROM playlist WHERE owner_id = :u) AS playlists,
                (SELECT count(*) FROM artist WHERE owner_id = :u) AS artists,
                (SELECT count(*) FROM report WHERE reporter_id = :u) AS reports_filed',
            ['u' => $userId->toRfc4122()],
        );

        return [
            'tracks' => (int) $row['tracks'],
            'posts' => (int) $row['posts'],
            'comments' => (int) $row['comments'],
            'playlists' => (int) $row['playlists'],
            'artists' => (int) $row['artists'],
            'reportsFiled' => (int) $row['reports_filed'],
        ];
    }

    /** Open reports on the user and on their content (tracks, posts, comments, playlists, artists). */
    public function openReportCount(Uuid $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT count(*) FROM report r WHERE r.status = 'open' AND (
                (r.target_type = 'user' AND r.target_id = :u)
                OR (r.target_type = 'track' AND r.target_id IN (SELECT id FROM track WHERE owner_id = :u))
                OR (r.target_type = 'post' AND r.target_id IN (SELECT id FROM post WHERE author_id = :u))
                OR (r.target_type = 'comment' AND r.target_id IN (SELECT id FROM comment WHERE author_id = :u))
                OR (r.target_type = 'playlist' AND r.target_id IN (SELECT id FROM playlist WHERE owner_id = :u))
                OR (r.target_type = 'artist' AND r.target_id IN (SELECT id FROM artist WHERE owner_id = :u)))",
            ['u' => $userId->toRfc4122()],
        );
    }

    public function openAlertCount(Uuid $userId): int
    {
        return (int) $this->db->fetchOne(
            "SELECT count(*) FROM security_alert WHERE subject_user_id = :u AND status = 'open'",
            ['u' => $userId->toRfc4122()],
        );
    }

    /**
     * Latest suspension journaled about the user: `admin.user_suspended`, or a W-33 `suspend`
     * decision (`admin.report_decided`, action suspend), within retention.
     *
     * @return array{created_at: string, actor_id: ?string, note: ?string}|null
     */
    public function lastSuspension(Uuid $userId): ?array
    {
        /** @var array{created_at: string, actor_id: ?string, note: ?string}|false $row */
        $row = $this->db->fetchAssociative(
            "SELECT created_at, actor_id, metadata->>'note' AS note FROM activity_event
             WHERE subject_user_id = :u
               AND (type = 'admin.user_suspended' OR (type = 'admin.report_decided' AND target_type = 'user' AND metadata->>'action' = 'suspend'))
             ORDER BY id DESC LIMIT 1",
            ['u' => $userId->toRfc4122()],
        );

        return false === $row ? null : $row;
    }
}
