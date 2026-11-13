<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AlertRule;
use App\Enum\AlertSeverity;
use App\Enum\AlertStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §16 (ADR 013). Raised by the detector of W-72 with `INSERT … ON CONFLICT` on the
 * generated column `subject_key` (partial unique WHERE status = 'open'). That column, the
 * partial unique index and the DESC index are written by hand in Version20261113000100 and
 * are not mapped: Doctrine must never write `subject_key`.
 */
#[ORM\Entity]
#[ORM\Table(name: 'security_alert')]
#[ORM\Index(name: 'idx_security_alert_subject_user', columns: ['subject_user_id'])]
class SecurityAlert
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 32, enumType: AlertRule::class)]
    private AlertRule $rule;

    #[ORM\Column(length: 16, enumType: AlertSeverity::class)]
    private AlertSeverity $severity;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?User $subjectUser = null;

    /** @var resource|string|null */
    #[ORM\Column(type: Types::BINARY, nullable: true)]
    private mixed $subjectIpHash = null;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $subjectTargetType = null;

    #[ORM\Column(type: UuidType::NAME, nullable: true)]
    private ?Uuid $subjectTargetId = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $hitCount = 1;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(length: 16, enumType: AlertStatus::class, options: ['default' => 'open'])]
    private AlertStatus $status = AlertStatus::Open;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $acknowledgedBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $note = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    private function __construct(AlertRule $rule, AlertSeverity $severity)
    {
        $this->id = Uuid::v7();
        $this->rule = $rule;
        $this->severity = $severity;
        $this->firstSeenAt = $this->lastSeenAt = $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRule(): AlertRule
    {
        return $this->rule;
    }

    public function getSeverity(): AlertSeverity
    {
        return $this->severity;
    }

    public function getSubjectUser(): ?User
    {
        return $this->subjectUser;
    }

    public function getSubjectIpHash(): ?string
    {
        if (\is_resource($this->subjectIpHash)) {
            $this->subjectIpHash = (string) stream_get_contents($this->subjectIpHash);
        }

        return \is_string($this->subjectIpHash) ? $this->subjectIpHash : null;
    }

    public function getSubjectTargetType(): ?string
    {
        return $this->subjectTargetType;
    }

    public function getSubjectTargetId(): ?Uuid
    {
        return $this->subjectTargetId;
    }

    public function getHitCount(): int
    {
        return $this->hitCount;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getStatus(): AlertStatus
    {
        return $this->status;
    }

    public function getAcknowledgedBy(): ?User
    {
        return $this->acknowledgedBy;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
