<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §8. Mapped for the schema only: rows are written with idempotent SQL (ON CONFLICT). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'follow')]
#[ORM\Index(name: 'idx_follow_followed_created', columns: ['followed_id', 'created_at', 'follower_id'])]
#[ORM\Index(name: 'idx_follow_follower_created', columns: ['follower_id', 'created_at', 'followed_id'])]
class Follow
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $follower,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $followed,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getFollower(): User
    {
        return $this->follower;
    }

    public function getFollowed(): User
    {
        return $this->followed;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
