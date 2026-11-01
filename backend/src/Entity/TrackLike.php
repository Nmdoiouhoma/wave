<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §8. Mapped for the schema only: rows are written with idempotent SQL (ON CONFLICT). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'track_like')]
#[ORM\Index(name: 'idx_track_like_track', columns: ['track_id'])]
#[ORM\Index(name: 'idx_track_like_user_created', columns: ['user_id', 'created_at', 'track_id'])]
class TrackLike
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Track::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Track $track,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
