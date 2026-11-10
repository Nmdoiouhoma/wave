<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §11. Mapped for the schema only: rows are upserted with SQL (W-32). */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'post_view')]
#[ORM\Index(name: 'idx_post_view_post', columns: ['post_id'])]
#[ORM\Index(name: 'idx_post_view_user_seen', columns: ['user_id', 'last_seen_at', 'post_id'])]
class PostView
{
    #[ORM\Column(options: ['default' => 0])]
    private int $watchedMs = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $completed = false;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $user,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Post::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
    ) {
        $this->firstSeenAt = $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getWatchedMs(): int
    {
        return $this->watchedMs;
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
