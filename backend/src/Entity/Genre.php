<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\GenreRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §5. Fixed list seeded by migration. */
#[ORM\Entity(repositoryClass: GenreRepository::class, readOnly: true)]
#[ORM\Table(name: 'genre')]
class Genre
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 32)]
        private string $id,
        #[ORM\Column(length: 50)]
        private string $name,
        #[ORM\Column(type: Types::SMALLINT)]
        private int $position,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
