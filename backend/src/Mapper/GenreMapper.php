<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\GenreView;
use App\Entity\Genre;

final readonly class GenreMapper
{
    public function toView(Genre $genre): GenreView
    {
        return new GenreView($genre->getId(), $genre->getName());
    }
}
