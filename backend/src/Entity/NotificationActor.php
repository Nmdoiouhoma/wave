<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §8b. Mapped for the schema only: rows are written with SQL (W-45). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notification_actor')]
#[ORM\Index(name: 'idx_notification_actor_actor', columns: ['actor_id'])]
class NotificationActor
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Notification::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Notification $notification,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $actor,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getNotification(): Notification
    {
        return $this->notification;
    }

    public function getActor(): User
    {
        return $this->actor;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
