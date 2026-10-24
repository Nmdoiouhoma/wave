<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\MeView;
use App\ApiResource\UserProfileView;
use App\ApiResource\UserSummary;
use App\Entity\User;
use App\Storage\ObjectStorage;

final readonly class UserMapper
{
    public function __construct(private ObjectStorage $storage)
    {
    }

    public function toSummary(User $user): UserSummary
    {
        return new UserSummary($user->getId()->toRfc4122(), $user->getUsername(), $user->getDisplayName(), $this->avatarUrl($user));
    }

    public function toProfile(User $user, ?User $viewer): UserProfileView
    {
        return new UserProfileView(
            $user->getId()->toRfc4122(),
            $user->getUsername(),
            $user->getDisplayName(),
            $this->avatarUrl($user),
            $user->getBio(),
            $user->getFollowersCount(),
            $user->getFollowingCount(),
            DateFormat::format($user->getCreatedAt()),
            self::isMe($user, $viewer),
            false, // follow graph lands in W-21
        );
    }

    public function toMe(User $user): MeView
    {
        return new MeView(
            $user->getId()->toRfc4122(),
            $user->getUsername(),
            $user->getDisplayName(),
            $this->avatarUrl($user),
            $user->getBio(),
            $user->getFollowersCount(),
            $user->getFollowingCount(),
            DateFormat::format($user->getCreatedAt()),
            true,
            false,
            $user->getEmail(),
            $user->isEmailVerified(),
            $user->getRoles(),
        );
    }

    private function avatarUrl(User $user): ?string
    {
        $key = $user->getAvatarKey();

        return null === $key ? null : $this->storage->publicUrl($key);
    }

    private static function isMe(User $user, ?User $viewer): bool
    {
        return null !== $viewer && $viewer->getId()->equals($user->getId());
    }
}
