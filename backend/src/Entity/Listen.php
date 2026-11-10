<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ListenSource;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §11: counted listens only (App\Listen\ListenRecorder). `post_id` (FK SET NULL since
 * W-30) is written by feed listens (W-32); player listens leave it null.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'listen')]
#[ORM\Index(name: 'idx_listen_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_listen_track_created', columns: ['track_id', 'created_at'])]
#[ORM\Index(name: 'idx_listen_user_track_created', columns: ['user_id', 'track_id', 'created_at'])]
class Listen
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Post::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Post $post = null;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Track::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Track $track,
        #[ORM\Column(length: 16, enumType: ListenSource::class)]
        private ListenSource $source,
        #[ORM\Column]
        private int $listenedMs,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTrack(): Track
    {
        return $this->track;
    }

    public function getSource(): ListenSource
    {
        return $this->source;
    }

    public function getListenedMs(): int
    {
        return $this->listenedMs;
    }

    public function getPostId(): ?Uuid
    {
        return $this->post?->getId();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
