<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ActivityCategory;
use App\Enum\ActivityType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §15 (ADR 013). Append-only, written only by App\Activity\ActivityJournal with
 * native SQL; this mapping is for reads. Indexes (DESC and partial ones) and CHECKs are written
 * by hand in Version20261113000100 and deliberately not mapped here.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'activity_event')]
class ActivityEvent
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: ActivityCategory::class)]
    private ActivityCategory $category;

    #[ORM\Column(length: 40, enumType: ActivityType::class)]
    private ActivityType $type;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $subjectUser = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $targetType = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $targetId = null;

    #[ORM\Column(length: 43, nullable: true)]
    private ?string $ipPrefix = null;

    /** @var resource|string|null bytea, 16 bytes */
    #[ORM\Column(type: Types::BINARY, nullable: true)]
    private mixed $ipHash = null;

    #[ORM\Column(length: 200, nullable: true)]
    private ?string $userAgent = null;

    /** @var array<string, int|bool|string> */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
    private array $metadata = [];

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct()
    {
        $this->id = Uuid::v7();
        $this->category = ActivityCategory::Auth;
        $this->type = ActivityType::LoginSucceeded;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCategory(): ActivityCategory
    {
        return $this->category;
    }

    public function getType(): ActivityType
    {
        return $this->type;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function getSubjectUser(): ?User
    {
        return $this->subjectUser;
    }

    public function getTargetType(): ?string
    {
        return $this->targetType;
    }

    public function getTargetId(): ?Uuid
    {
        return $this->targetId;
    }

    public function getIpPrefix(): ?string
    {
        return $this->ipPrefix;
    }

    /** Raw 16-byte keyed hash, or null. */
    public function getIpHash(): ?string
    {
        if (\is_resource($this->ipHash)) {
            $this->ipHash = (string) stream_get_contents($this->ipHash);
        }

        return \is_string($this->ipHash) ? $this->ipHash : null;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /** @return array<string, int|bool|string> */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
