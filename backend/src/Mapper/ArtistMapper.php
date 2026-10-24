<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\ArtistSummary;
use App\ApiResource\ArtistView;
use App\Entity\Artist;
use App\Entity\User;
use App\Storage\ObjectStorage;

final readonly class ArtistMapper
{
    public function __construct(private ObjectStorage $storage, private UserMapper $users)
    {
    }

    public function toSummary(Artist $artist): ArtistSummary
    {
        return new ArtistSummary($artist->getId()->toRfc4122(), $artist->getSlug(), $artist->getName(), $this->avatarUrl($artist));
    }

    public function toView(Artist $artist, ?User $viewer): ArtistView
    {
        return new ArtistView(
            $artist->getId()->toRfc4122(),
            $artist->getSlug(),
            $artist->getName(),
            $this->avatarUrl($artist),
            $artist->getBio(),
            $artist->getTrackCount(),
            $this->users->toSummary($artist->getOwner()),
            $artist->getModeration()->value,
            $artist->isOwnedBy($viewer),
            DateFormat::format($artist->getCreatedAt()),
        );
    }

    private function avatarUrl(Artist $artist): ?string
    {
        $key = $artist->getAvatarKey();

        return null === $key ? null : $this->storage->publicUrl($key);
    }
}
