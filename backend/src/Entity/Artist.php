<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Moderation;
use App\Repository\ArtistRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** DATA_MODEL §4 (D6, ADR 004). `trackCount` is a recount maintained by ArtistRepository::recountTracks(). */
#[ORM\Entity(repositoryClass: ArtistRepository::class)]
#[ORM\Table(name: 'artist')]
#[ORM\UniqueConstraint(name: 'uniq_artist_slug', columns: ['slug'])]
#[ORM\Index(name: 'idx_artist_owner_created', columns: ['owner_id', 'created_at'])]
class Artist
{
    public const int MAX_PER_USER = 10;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 80)]
    private string $slug;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $avatarKey = null;

    #[ORM\Column(length: 16, enumType: Moderation::class, options: ['default' => 'visible'])]
    private Moderation $moderation = Moderation::Visible;

    #[ORM\Column(options: ['default' => 0])]
    private int $trackCount = 0;

    /** Maintained by trigger; never written by the ORM. */
    #[ORM\Column(type: 'tsvector', insertable: false, updatable: false, options: ['default' => ''])]
    private ?string $searchVector = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $slug, string $name, ?string $bio = null)
    {
        $this->id = Uuid::v7();
        $this->owner = $owner;
        $this->slug = $slug;
        $this->name = $name;
        $this->bio = $bio;
        $this->createdAt = new \DateTimeImmutable();
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

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): void
    {
        $this->bio = $bio;
    }

    public function getAvatarKey(): ?string
    {
        return $this->avatarKey;
    }

    /** Media contract (CONVENTIONS §8): called by the ProcessImage handler. */
    public function setAvatarKey(?string $avatarKey): void
    {
        $this->avatarKey = $avatarKey;
    }

    public function getModeration(): Moderation
    {
        return $this->moderation;
    }

    public function setModeration(Moderation $moderation): void
    {
        $this->moderation = $moderation;
    }

    public function getTrackCount(): int
    {
        return $this->trackCount;
    }

    /** @internal mirrors the SQL recount on the managed instance (TrackCountListener) */
    public function syncTrackCount(int $count): void
    {
        $this->trackCount = $count;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
