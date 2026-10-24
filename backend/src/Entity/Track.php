<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Enum\Moderation;
use App\Enum\Visibility;
use App\Repository\TrackRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §6. Media mutators (markReady, markFailed, setCoverKey) are part of the
 * media-infra contract (CONVENTIONS §8). Artist track_count is recounted by
 * App\Doctrine\TrackCountListener whenever a track is inserted, updated or removed.
 */
#[ORM\Entity(repositoryClass: TrackRepository::class)]
#[ORM\Table(name: 'track')]
#[ORM\Index(name: 'idx_track_artist_created', columns: ['artist_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_track_owner_created', columns: ['owner_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_track_genre', columns: ['genre_id'])]
#[ORM\Index(name: 'idx_track_trending', columns: ['trending_score', 'id'], options: ['where' => "(((status)::text = 'ready'::text) AND ((visibility)::text = 'public'::text) AND ((moderation)::text = 'visible'::text))"])]
#[ORM\HasLifecycleCallbacks]
class Track
{
    public const int MAX_SIZE_BYTES = 31_457_280;
    public const int MAX_DURATION_MS = 600_000;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Artist::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Artist $artist;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\ManyToOne(targetEntity: Genre::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Genre $genre;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $rawKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $audioKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    #[ORM\Column]
    private int $sizeBytes;

    #[ORM\Column(length: 16, enumType: MediaStatus::class, options: ['default' => 'processing'])]
    private MediaStatus $status = MediaStatus::Processing;

    #[ORM\Column(length: 32, nullable: true, enumType: FailureReason::class)]
    private ?FailureReason $failureReason = null;

    #[ORM\Column(length: 16, enumType: Visibility::class, options: ['default' => 'public'])]
    private Visibility $visibility;

    #[ORM\Column(options: ['default' => true])]
    private bool $allowClips;

    #[ORM\Column(length: 16, enumType: Moderation::class, options: ['default' => 'visible'])]
    private Moderation $moderation = Moderation::Visible;

    #[ORM\Column(options: ['default' => 0])]
    private int $playCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $likeCount = 0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    private float $trendingScore = 0.0;

    /** Maintained by trigger; never written by the ORM. */
    #[ORM\Column(type: 'tsvector', insertable: false, updatable: false, options: ['default' => ''])]
    private ?string $searchVector = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        Artist $artist,
        Genre $genre,
        string $title,
        int $sizeBytes,
        Visibility $visibility = Visibility::Public,
        bool $allowClips = true,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->artist = $artist;
        $this->owner = $artist->getOwner();
        $this->genre = $genre;
        $this->title = $title;
        $this->sizeBytes = $sizeBytes;
        $this->visibility = $visibility;
        $this->allowClips = $allowClips;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Media contract: processed audio stored, quarantine object no longer referenced. */
    public function markReady(string $audioKey, int $durationMs): void
    {
        $this->audioKey = $audioKey;
        $this->durationMs = $durationMs;
        $this->status = MediaStatus::Ready;
        $this->failureReason = null;
        $this->rawKey = null;
    }

    /** Media contract: the quarantine object is kept (swept later by CleanupQuarantine). */
    public function markFailed(FailureReason $reason): void
    {
        $this->status = MediaStatus::Failed;
        $this->failureReason = $reason;
    }

    public function isPublicVisible(): bool
    {
        return MediaStatus::Ready === $this->status
            && Visibility::Public === $this->visibility
            && Moderation::Visible === $this->moderation;
    }

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && $this->owner->getId()->equals($user->getId());
    }

    /** DATA_MODEL §6: owner and moderators see everything, others only public-visible tracks. */
    public function isVisibleTo(?User $viewer): bool
    {
        return $this->isPublicVisible() || $this->isOwnedBy($viewer) || (null !== $viewer && $viewer->isModerator());
    }

    /** D5: may $viewer create a post from this track now. */
    public function canBeClippedBy(?User $viewer): bool
    {
        if (null === $viewer || MediaStatus::Ready !== $this->status || Moderation::Visible !== $this->moderation) {
            return false;
        }

        return $this->isOwnedBy($viewer) || (Visibility::Public === $this->visibility && $this->allowClips);
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getArtist(): Artist
    {
        return $this->artist;
    }

    /** Only the owner's own artists are allowed, so the owner never changes (ADR 004). */
    public function moveToArtist(Artist $artist): void
    {
        if (!$artist->getOwner()->getId()->equals($this->owner->getId())) {
            throw new \LogicException('A track can only move between artists of the same owner.');
        }
        $this->artist = $artist;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getGenre(): Genre
    {
        return $this->genre;
    }

    public function setGenre(Genre $genre): void
    {
        $this->genre = $genre;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getCoverKey(): ?string
    {
        return $this->coverKey;
    }

    /** Media contract (CONVENTIONS §8): called by the ProcessImage handler. */
    public function setCoverKey(?string $coverKey): void
    {
        $this->coverKey = $coverKey;
    }

    public function getRawKey(): ?string
    {
        return $this->rawKey;
    }

    public function setRawKey(?string $rawKey): void
    {
        $this->rawKey = $rawKey;
    }

    public function getAudioKey(): ?string
    {
        return $this->audioKey;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function getSizeBytes(): int
    {
        return $this->sizeBytes;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function getFailureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    public function getVisibility(): Visibility
    {
        return $this->visibility;
    }

    public function setVisibility(Visibility $visibility): void
    {
        $this->visibility = $visibility;
    }

    public function isAllowClips(): bool
    {
        return $this->allowClips;
    }

    public function setAllowClips(bool $allowClips): void
    {
        $this->allowClips = $allowClips;
    }

    public function getModeration(): Moderation
    {
        return $this->moderation;
    }

    public function setModeration(Moderation $moderation): void
    {
        $this->moderation = $moderation;
    }

    public function getPlayCount(): int
    {
        return $this->playCount;
    }

    public function getLikeCount(): int
    {
        return $this->likeCount;
    }

    public function getTrendingScore(): float
    {
        return $this->trendingScore;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
