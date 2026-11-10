<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ReportReason;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** DATA_MODEL §13. Polymorphic target (no FK). Table created by W-30; behaviour arrives with W-33. */
#[ORM\Entity]
#[ORM\Table(name: 'report')]
#[ORM\UniqueConstraint(name: 'uniq_report_reporter_target', columns: ['reporter_id', 'target_type', 'target_id'])]
#[ORM\Index(name: 'idx_report_status_created', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'idx_report_target_status', columns: ['target_type', 'target_id', 'status'])]
class Report
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: ReportStatus::class, options: ['default' => 'open'])]
    private ReportStatus $status = ReportStatus::Open;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $handledBy = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $handledAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $decisionNote = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $reporter,
        #[ORM\Column(length: 16, enumType: ReportTargetType::class)]
        private ReportTargetType $targetType,
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $targetId,
        #[ORM\Column(length: 16, enumType: ReportReason::class)]
        private ReportReason $reason,
        #[ORM\Column(length: 1000, nullable: true)]
        private ?string $details = null,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getReporter(): User
    {
        return $this->reporter;
    }

    public function getTargetType(): ReportTargetType
    {
        return $this->targetType;
    }

    public function getTargetId(): Uuid
    {
        return $this->targetId;
    }

    public function getReason(): ReportReason
    {
        return $this->reason;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function getStatus(): ReportStatus
    {
        return $this->status;
    }

    public function getHandledBy(): ?User
    {
        return $this->handledBy;
    }

    public function getHandledAt(): ?\DateTimeImmutable
    {
        return $this->handledAt;
    }

    public function getDecisionNote(): ?string
    {
        return $this->decisionNote;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
