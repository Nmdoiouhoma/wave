<?php

declare(strict_types=1);

namespace App\Admin;

use App\ApiResource\AdminUserDetailView;
use App\ApiResource\AdminUserView;
use App\Entity\User;
use App\Mapper\DateFormat;
use App\Mapper\UserMapper;
use App\Repository\UserRepository;
use Symfony\Component\Uid\Uuid;

/** `AdminUser` / `AdminUserDetail` views: `email` and `openAlertCount` only for an admin caller. */
final readonly class AdminUserMapper
{
    public function __construct(
        private UserMapper $users,
        private AdminUserQuery $query,
        private UserRepository $repository,
    ) {
    }

    /** Highest role (`AdminRole`). */
    public static function role(User $user): string
    {
        return match (true) {
            $user->isAdmin() => AdminUserQuery::ROLE_ADMIN,
            $user->isModerator() => AdminUserQuery::ROLE_MODERATOR,
            default => AdminUserQuery::ROLE_USER,
        };
    }

    public function toView(User $user, bool $admin): AdminUserView
    {
        $summary = $this->users->toSummary($user);

        return new AdminUserView(
            $summary->id,
            $summary->username,
            $summary->displayName,
            $summary->avatarUrl,
            $admin ? $user->getEmail() : null,
            $user->isEmailVerified(),
            self::role($user),
            DateFormat::nullable($user->getSuspendedAt()),
            DateFormat::format($user->getCreatedAt()),
            DateFormat::nullable($user->getLastLoginAt()),
        );
    }

    public function toDetail(User $user, bool $admin): AdminUserDetailView
    {
        $view = $this->toView($user, $admin);
        $id = $user->getId();
        $counts = $this->query->counts($id);
        $suspension = $this->query->lastSuspension($id);
        $by = null === $suspension || null === $suspension['actor_id'] ? null : $this->repository->find(Uuid::fromString($suspension['actor_id']));

        return new AdminUserDetailView(
            $view->id,
            $view->username,
            $view->displayName,
            $view->avatarUrl,
            $view->email,
            $view->emailVerified,
            $view->role,
            $view->suspendedAt,
            $view->createdAt,
            $view->lastLoginAt,
            [
                'tracks' => $counts['tracks'],
                'posts' => $counts['posts'],
                'comments' => $counts['comments'],
                'playlists' => $counts['playlists'],
                'artists' => $counts['artists'],
                'followers' => $user->getFollowersCount(),
                'following' => $user->getFollowingCount(),
                'reportsFiled' => $counts['reportsFiled'],
            ],
            $this->query->openReportCount($id),
            $admin ? $this->query->openAlertCount($id) : null,
            null === $suspension ? null : [
                'at' => DateFormat::format(new \DateTimeImmutable($suspension['created_at'])),
                'by' => null === $by ? null : $this->users->toSummary($by),
                'note' => $suspension['note'],
            ],
        );
    }
}
