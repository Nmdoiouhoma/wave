<?php

declare(strict_types=1);

namespace App\Command\Admin;

use App\Activity\ActivityJournal;
use App\Enum\ActivityType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * ROLE_ADMIN is granted and revoked only from the console (ADR 013, DATA_MODEL §2). One
 * transaction: the admins are locked first (ORDER BY id, so two revokes serialize), then the
 * target row; `admin.admin_granted` / `admin.admin_revoked` is written in it with actor NULL.
 * Revoking keeps the other roles (an admin who was also moderator stays moderator).
 * W-77 F27: a suspended or unverified account is never granted; the last-admin guard counts
 * only non-suspended admins (revoking a suspended admin is always allowed).
 */
final readonly class AdminRoleChanger
{
    public const string ADMIN = 'ROLE_ADMIN';
    public const string MODERATOR = 'ROLE_MODERATOR';

    public function __construct(private Connection $db, private ActivityJournal $journal)
    {
    }

    public function change(string $email, bool $grant): AdminRoleOutcome
    {
        return $this->db->transactional(function (Connection $db) use ($email, $grant): AdminRoleOutcome {
            $activeAdmins = array_filter($db->fetchAllAssociative(
                "SELECT id, suspended_at FROM app_user WHERE roles::jsonb @> '[\"ROLE_ADMIN\"]'::jsonb ORDER BY id FOR UPDATE",
            ), static fn (array $admin): bool => null === $admin['suspended_at']);
            $row = $db->fetchAssociative(
                'SELECT id, roles, suspended_at, email_verified_at FROM app_user WHERE email = :e FOR UPDATE',
                ['e' => mb_strtolower(trim($email))],
            );
            if (false === $row) {
                return AdminRoleOutcome::NotFound;
            }
            /** @var list<string> $roles */
            $roles = json_decode((string) $row['roles'], true, 4, \JSON_THROW_ON_ERROR);
            $isAdmin = \in_array(self::ADMIN, $roles, true);
            if ($grant === $isAdmin) {
                return AdminRoleOutcome::Unchanged;
            }
            if ($grant && null !== $row['suspended_at']) {
                return AdminRoleOutcome::Suspended;
            }
            if ($grant && null === $row['email_verified_at']) {
                return AdminRoleOutcome::Unverified;
            }
            if (!$grant && null === $row['suspended_at'] && \count($activeAdmins) <= 1) {
                return AdminRoleOutcome::LastAdmin;
            }
            $next = $grant ? [...$roles, self::ADMIN] : array_values(array_diff($roles, [self::ADMIN]));
            $db->executeStatement(
                'UPDATE app_user SET roles = CAST(:r AS json) WHERE id = :id',
                ['r' => json_encode(array_values(array_unique($next)), \JSON_THROW_ON_ERROR), 'id' => $row['id']],
            );
            $this->journal->record(
                $grant ? ActivityType::AdminGranted : ActivityType::AdminRevoked,
                null,
                Uuid::fromString((string) $row['id']),
                metadata: ['from' => self::highest($roles), 'to' => self::highest($next)],
            );

            return AdminRoleOutcome::Changed;
        });
    }

    /** @param list<string> $roles */
    private static function highest(array $roles): string
    {
        return match (true) {
            \in_array(self::ADMIN, $roles, true) => self::ADMIN,
            \in_array(self::MODERATOR, $roles, true) => self::MODERATOR,
            default => 'ROLE_USER',
        };
    }
}
