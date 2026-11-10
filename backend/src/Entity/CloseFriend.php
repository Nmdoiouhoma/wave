<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DATA_MODEL §8 (FB-18, ADR 012): one close-friends list per user. Mapped for the schema only:
 * rows are written with idempotent SQL. The composite FK (friend_id, owner_id) → follow
 * (follower_id, followed_id) ON DELETE CASCADE is hand-written in the migration (members are
 * followers; an unfollow removes the membership in the same statement).
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'close_friend')]
#[ORM\Index(name: 'idx_close_friend_friend_owner', columns: ['friend_id', 'owner_id'])]
class CloseFriend
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $owner,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $friend,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getFriend(): User
    {
        return $this->friend;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
