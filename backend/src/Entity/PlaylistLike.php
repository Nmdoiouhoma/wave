<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §8. Mapped for the schema only: rows are written with idempotent SQL (ON CONFLICT). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'playlist_like')]
#[ORM\Index(name: 'idx_playlist_like_playlist', columns: ['playlist_id'])]
#[ORM\Index(name: 'idx_playlist_like_user_created', columns: ['user_id', 'created_at', 'playlist_id'])]
class PlaylistLike
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Playlist::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Playlist $playlist,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
