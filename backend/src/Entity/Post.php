<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Enum\Moderation;
use App\Enum\VisualType;
use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §9. Media contract (CONVENTIONS §8, handlers by media-infra): `setClipKey`,
 * `setVisual`, `markFailed`, then `PostRepository::tryMarkReady()` (the conditional SQL of §9,
 * so the two independent handlers never race each other into a wrong status).
 * Counters are moved with atomic SQL only, never through this entity.
 */
#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'post')]
#[ORM\Index(name: 'idx_post_author_created', columns: ['author_id', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_post_track', columns: ['track_id'])]
#[ORM\Index(name: 'idx_post_ready_created', columns: ['created_at', 'id'], options: ['where' => "(((status)::text = 'ready'::text) AND ((moderation)::text = 'visible'::text))"])]
#[ORM\Index(name: 'idx_post_ready_trending', columns: ['trending_score', 'id'], options: ['where' => "(((status)::text = 'ready'::text) AND ((moderation)::text = 'visible'::text))"])]
class Post
{
    public const int MIN_CLIP_MS = 15_000;
    public const int MAX_CLIP_MS = 60_000;
    public const int CAPTION_MAX = 300;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $author;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Track $track;

    #[ORM\Column]
    private int $clipStartMs;

    #[ORM\Column]
    private int $clipDurationMs;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clipKey = null;

    #[ORM\Column(length: 16, nullable: true, enumType: VisualType::class)]
    private ?VisualType $visualType = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $visualRawKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $visualKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $visualPosterKey = null;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $caption;

    #[ORM\Column(length: 16, enumType: MediaStatus::class, options: ['default' => 'processing'])]
    private MediaStatus $status = MediaStatus::Processing;

    #[ORM\Column(length: 32, nullable: true, enumType: FailureReason::class)]
    private ?FailureReason $failureReason = null;

    #[ORM\Column(length: 16, enumType: Moderation::class, options: ['default' => 'visible'])]
    private Moderation $moderation = Moderation::Visible;

    #[ORM\Column(options: ['default' => 0])]
    private int $likeCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $commentCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $viewCount = 0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0])]
    private float $trendingScore = 0.0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $author, Track $track, int $clipStartMs, int $clipDurationMs, ?string $caption, ?Uuid $id = null)
    {
        $this->id = $id ?? Uuid::v7();
        $this->author = $author;
        $this->track = $track;
        $this->clipStartMs = $clipStartMs;
        $this->clipDurationMs = $clipDurationMs;
        $this->caption = $caption;
        $this->createdAt = new \DateTimeImmutable();
    }

    /** The uploaded visual waits in quarantine (`quarantine/posts/{postId}/visual`) for ProcessPostVisual. */
    public function attachRawVisual(VisualType $type, string $rawKey): void
    {
        $this->visualType = $type;
        $this->visualRawKey = $rawKey;
    }

    /** Media contract (GenerateClip): `audio/clips/{postId}.m4a` written. Then call PostRepository::tryMarkReady(). */
    public function setClipKey(string $clipKey): void
    {
        $this->clipKey = $clipKey;
    }

    /** Media contract (ProcessPostVisual): public keys written, quarantine key no longer referenced. Then tryMarkReady(). */
    public function setVisual(string $visualKey, ?string $posterKey): void
    {
        $this->visualKey = $visualKey;
        $this->visualPosterKey = $posterKey;
        $this->visualRawKey = null;
    }

    /** Media contract: any handler failure. The quarantine object is kept (swept by CleanupQuarantine). */
    public function markFailed(FailureReason $reason): void
    {
        $this->status = MediaStatus::Failed;
        $this->failureReason = $reason;
    }

    // "Playable" and "visible to a caller" depend on the viewer (FB-18): App\Security\TrackAudience.

    public function isAuthoredBy(?User $user): bool
    {
        return null !== $user && $this->author->getId()->equals($user->getId());
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getClipStartMs(): int
    {
        return $this->clipStartMs;
    }

    public function getClipDurationMs(): int
    {
        return $this->clipDurationMs;
    }

    public function getClipKey(): ?string
    {
        return $this->clipKey;
    }

    public function getVisualType(): ?VisualType
    {
        return $this->visualType;
    }

    public function getVisualRawKey(): ?string
    {
        return $this->visualRawKey;
    }

    public function getVisualKey(): ?string
    {
        return $this->visualKey;
    }

    public function getVisualPosterKey(): ?string
    {
        return $this->visualPosterKey;
    }

    public function getCaption(): ?string
    {
        return $this->caption;
    }

    public function getStatus(): MediaStatus
    {
        return $this->status;
    }

    public function getFailureReason(): ?FailureReason
    {
        return $this->failureReason;
    }

    public function getModeration(): Moderation
    {
        return $this->moderation;
    }

    public function getLikeCount(): int
    {
        return $this->likeCount;
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    public function getViewCount(): int
    {
        return $this->viewCount;
    }

    public function getTrendingScore(): float
    {
        return $this->trendingScore;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
