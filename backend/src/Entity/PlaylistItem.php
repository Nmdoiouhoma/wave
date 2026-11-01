<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §7. Positions are 0-based and contiguous; UNIQUE(playlist_id, position) is
 * DEFERRABLE INITIALLY DEFERRED (migration) so reorders rewrite every position in one go.
 * Written with SQL by PlaylistRepository.
 */
#[ORM\Entity]
#[ORM\Table(name: 'playlist_item')]
#[ORM\UniqueConstraint(name: 'uniq_playlist_item_track', columns: ['playlist_id', 'track_id'])]
#[ORM\UniqueConstraint(name: 'uniq_playlist_item_position', columns: ['playlist_id', 'position'])]
class PlaylistItem
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Playlist::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Playlist $playlist;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Track $track;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $addedAt;

    public function __construct(Playlist $playlist, Track $track, int $position)
    {
        $this->id = Uuid::v7();
        $this->playlist = $playlist;
        $this->track = $track;
        $this->position = $position;
        $this->addedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPlaylist(): Playlist
    {
        return $this->playlist;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
