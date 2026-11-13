<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AffinityKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §12. Mapped for the schema only: rows are rewritten with SQL by RecomputeAffinity (ADR 005). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'user_affinity')]
#[ORM\Index(name: 'idx_user_affinity_score', columns: ['user_id', 'kind', 'score'], options: ['order' => ['score' => 'DESC']])]
class UserAffinity
{
    #[ORM\Column(type: Types::FLOAT)]
    private float $score;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Id]
        #[ORM\Column(length: 16, enumType: AffinityKind::class)]
        private AffinityKind $kind,
        #[ORM\Id]
        #[ORM\Column(name: '"key"', length: 64)]
        private string $key,
        float $score,
    ) {
        $this->score = $score;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKind(): AffinityKind
    {
        return $this->kind;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
