<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DATA_MODEL §11, ADR 010 (W-26 F13): proof that a stream URL was issued to (user, track).
 * Written and consumed in SQL only (App\Repository\ListenGrantRepository); mapped so that the
 * schema stays described by Doctrine.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'listen_grant')]
class ListenGrant
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Track::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Track $track,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
        private \DateTimeImmutable $issuedAt,
    ) {
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }
}
