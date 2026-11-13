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
        if ($target->isAdmin()) {
            return false;
        }

        return !$target->isModerator() || $actor->isAdmin();
    }
}
