<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\TrackUploadStatus;
use App\Repository\TrackUploadRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §6b (ADR 009): one direct-to-storage audio upload slot. Consumed once by
 * `POST /tracks` (TrackUploadRepository::consume), expired by CleanupQuarantine after 2 h.
 */
#[ORM\Entity(repositoryClass: TrackUploadRepository::class)]
#[ORM\Table(name: 'track_upload')]
#[ORM\UniqueConstraint(name: 'uniq_track_upload_key', columns: ['quarantine_key'])]
#[ORM\Index(name: 'idx_track_upload_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_track_upload_status_created', columns: ['status', 'created_at'])]
class TrackUpload
{
    public const int MAX_PENDING_PER_USER = 10;
    public const int MP4_MAX_BYTES = 209_715_200;
    public const int AUDIO_MAX_BYTES = 31_457_280;
    public const string POLICY_TTL = 'PT15M';
    public const string CONSUME_WINDOW = 'PT2H';

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $quarantineKey;

    #[ORM\Column(length: 16, enumType: TrackUploadStatus::class, options: ['default' => 'pending'])]
    private TrackUploadStatus $status = TrackUploadStatus::Pending;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consumedAt = null;

    #[ORM\ManyToOne(targetEntity: Track::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Track $track = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        User $user,
        #[ORM\Column(length: 255)]
        private string $declaredFilename,
        #[ORM\Column]
        private int $declaredSize,
        #[ORM\Column(length: 32)]
        private string $declaredType,
        #[ORM\Column]
        private int $maxBytes,
        \DateTimeImmutable $now,
    ) {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->quarantineKey = self::keyFor($user->getId(), $this->id);
        $this->createdAt = $now;
        $this->expiresAt = $now->add(new \DateInterval(self::POLICY_TTL));
    }

    public static function keyFor(Uuid $userId, Uuid $uploadId): string
    {
        return 'quarantine/uploads/'.$userId->toRfc4122().'/'.$uploadId->toRfc4122();
    }

    /** ADR 009: 200 MB for `.mp4`, 30 MB for every other accepted extension. */
    public static function maxBytesFor(string $extension): int
    {
        return 'mp4' === strtolower($extension) ? self::MP4_MAX_BYTES : self::AUDIO_MAX_BYTES;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getQuarantineKey(): string
    {
        return $this->quarantineKey;
    }

    public function getDeclaredFilename(): string
    {
        return $this->declaredFilename;
    }

    public function getDeclaredSize(): int
    {
        return $this->declaredSize;
    }

    public function getDeclaredType(): string
    {
        return $this->declaredType;
    }

    public function getMaxBytes(): int
    {
        return $this->maxBytes;
    }

    public function getStatus(): TrackUploadStatus
    {
        return $this->status;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getConsumedAt(): ?\DateTimeImmutable
    {
        return $this->consumedAt;
    }

    public function getTrack(): ?Track
    {
        return $this->track;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
