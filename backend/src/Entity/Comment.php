<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Moderation;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/** DATA_MODEL §10. Table created by W-30; behaviour arrives with W-33. */
#[ORM\Entity]
#[ORM\Table(name: 'comment')]
#[ORM\Index(name: 'idx_comment_post_created', columns: ['post_id', 'created_at', 'id'])]
class Comment
{
    public const int BODY_MAX = 500;

    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(length: 16, enumType: Moderation::class, options: ['default' => 'visible'])]
    private Moderation $moderation = Moderation::Visible;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Post::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private User $author,
        #[ORM\Column(length: 500)]
        private string $body,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getModeration(): Moderation
    {
        return $this->moderation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
