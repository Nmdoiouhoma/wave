<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * DATA_MODEL §9b (FB-33, ADR 015). Mapped for the schema only: rows are written with idempotent
 * SQL (ON CONFLICT), exactly like `post_like`. PK(user_id, post_id), both FKs CASCADE; the
 * counter lives in `post.repost_count`.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'repost')]
#[ORM\Index(name: 'idx_repost_post', columns: ['post_id'])]
#[ORM\Index(name: 'idx_repost_user_created', columns: ['user_id', 'created_at', 'post_id'])]
class Repost
{
    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

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
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
