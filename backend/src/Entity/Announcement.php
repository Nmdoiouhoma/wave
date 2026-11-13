<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AnnouncementLevel;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** DATA_MODEL §17 (ADR 013). Plain-text banner; API in W-72. CHECK ends_at > starts_at in the migration. */
#[ORM\Entity]
#[ORM\Table(name: 'announcement')]
#[ORM\Index(name: 'idx_announcement_window', columns: ['starts_at', 'ends_at'])]
class Announcement
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Column(length: 80)]
        private string $title,
        #[ORM\Column(length: 500)]
        private string $body,
        #[ORM\Column(length: 16, enumType: AnnouncementLevel::class)]
        private AnnouncementLevel $level,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $startsAt,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
        private ?\DateTimeImmutable $endsAt = null,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $createdBy = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getLevel(): AnnouncementLevel
    {
        return $this->level;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * W-72 partial update (PATCH): null keeps the value, except `$endsAt` when `$setEndsAt`.
     * Always bumps `updatedAt` so clients show an edited banner again.
     */
    public function update(?string $title, ?string $body, ?AnnouncementLevel $level, ?\DateTimeImmutable $startsAt, bool $setEndsAt, ?\DateTimeImmutable $endsAt): void
    {
        $this->title = $title ?? $this->title;
        $this->body = $body ?? $this->body;
        $this->level = $level ?? $this->level;
        $this->startsAt = $startsAt ?? $this->startsAt;
        if ($setEndsAt) {
            $this->endsAt = $endsAt;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }
}
