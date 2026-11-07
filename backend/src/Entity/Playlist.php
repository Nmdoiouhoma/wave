<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Moderation;
use App\Repository\PlaylistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** DATA_MODEL §7. Counters (item_count, like_count) are changed by repository SQL only. */
#[ORM\Entity(repositoryClass: PlaylistRepository::class)]
#[ORM\Table(name: 'playlist')]
#[ORM\Index(name: 'idx_playlist_owner_created', columns: ['owner_id', 'created_at', 'id'])]
class Playlist
{
    public const int MAX_ITEMS = 500;
    /** Per owner (DATA_MODEL §7, W-26 F15). */
    public const int MAX_PER_USER = 200;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(length: 300, nullable: true)]
    private ?string $description;

    #[ORM\Column(options: ['default' => true])]
    private bool $isPublic;

    #[ORM\Column(length: 16, enumType: Moderation::class, options: ['default' => 'visible'])]
    private Moderation $moderation = Moderation::Visible;

    #[ORM\Column(options: ['default' => 0])]
    private int $itemCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $likeCount = 0;

    /** Maintained by trigger; never written by the ORM. */
    #[ORM\Column(type: 'tsvector', insertable: false, updatable: false, options: ['default' => ''])]
    private ?string $searchVector = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $owner, string $name, ?string $description = null, bool $isPublic = true)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->name = $name;
        $this->description = $description;
        $this->isPublic = $isPublic;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function isOwnedBy(?User $user): bool
    {
        return null !== $user && $this->owner->getId()->equals($user->getId());
    }

    /** DATA_MODEL §7: others see it only if public and not hidden; owner and moderators always. */
    public function isVisibleTo(?User $viewer): bool
    {
        return ($this->isPublic && Moderation::Visible === $this->moderation)
            || $this->isOwnedBy($viewer)
            || (null !== $viewer && $viewer->isModerator());
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->touch();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $this->description = $description;
        $this->touch();
    }

    public function isPublic(): bool
    {
        return $this->isPublic;
    }

    public function setPublic(bool $isPublic): void
    {
        $this->isPublic = $isPublic;
        $this->touch();
    }

    public function getModeration(): Moderation
    {
        return $this->moderation;
    }

    public function setModeration(Moderation $moderation): void
    {
        $this->moderation = $moderation;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function getLikeCount(): int
    {
        return $this->likeCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
