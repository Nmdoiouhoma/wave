<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\GenreView;
use App\Mapper\GenreMapper;
use App\Repository\GenreRepository;

/** @implements ProviderInterface<GenreView> */
final readonly class GenreProvider implements ProviderInterface
{
    public function __construct(private GenreRepository $genres, private GenreMapper $mapper)
    {
    }

    /** @return list<GenreView> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map($this->mapper->toView(...), $this->genres->findAllOrdered());
    }
}
