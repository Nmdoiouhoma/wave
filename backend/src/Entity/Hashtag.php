<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * DATA_MODEL §9. Rows are upserted with SQL (App\Post\HashtagWriter); `post_count` moves with
 * atomic SQL only. The prefix (varchar_pattern_ops) and trigram indexes live in the migration.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'hashtag')]
#[ORM\UniqueConstraint(name: 'uniq_hashtag_name', columns: ['name'])]
class Hashtag
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME)]
    private Uuid $id;

    #[ORM\Column(options: ['default' => 0])]
    private int $postCount = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\Column(length: 50)]
        private string $name,
    ) {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPostCount(): int
    {
        return $this->postCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
