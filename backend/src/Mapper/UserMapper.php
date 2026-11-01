<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\MeView;
use App\ApiResource\UserListItem;
use App\ApiResource\UserProfileView;
use App\ApiResource\UserSummary;
use App\Entity\User;
use App\Repository\FollowRepository;
use App\Storage\ObjectStorage;

final readonly class UserMapper
{
    public function __construct(private ObjectStorage $storage, private FollowRepository $follows)
    {
    }

    /**
     * @param list<User> $users
     *
     * @return list<UserListItem> isFollowedByMe batch-loaded (one query)
     */
    public function toListItems(array $users, ?User $viewer): array
    {
        $followed = $this->follows->followedAmong($viewer, array_map(static fn (User $u): \Symfony\Component\Uid\Uuid => $u->getId(), $users));

        return array_map(fn (User $u): UserListItem => new UserListItem(
            $u->getId()->toRfc4122(),
            $u->getUsername(),
            $u->getDisplayName(),
            $this->avatarUrl($u),
            $u->getFollowersCount(),
            self::isMe($u, $viewer),
            isset($followed[$u->getId()->toRfc4122()]),
        ), $users);
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
            isset($this->follows->followedAmong($viewer, [$user->getId()])[$user->getId()->toRfc4122()]),
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
