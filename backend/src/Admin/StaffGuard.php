<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\User;

/**
 * ADR 013 §2: who may act on whom. Never on an admin; on a moderator only as an admin; on a
 * plain user as any staff member. Self is handled by each route (422 or 403, per the contract).
 */
final class StaffGuard
{
    public static function canActOn(User $actor, User $target): bool
    {
        return self::canActOnRoles($actor, $target->getRoles());
    }

    /**
     * Same rule on raw stored roles, for a re-check on a row locked `FOR UPDATE` (W-77 F26).
     *
     * @param array<string> $targetRoles
     */
    public static function canActOnRoles(User $actor, array $targetRoles): bool
    {
        if (\in_array('ROLE_ADMIN', $targetRoles, true)) {
            return false;
        }

        return !\in_array('ROLE_MODERATOR', $targetRoles, true) || $actor->isAdmin();
    }
}
