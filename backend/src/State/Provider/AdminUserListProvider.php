<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Admin\AdminQueryParams;
use App\Admin\AdminUserMapper;
use App\Admin\AdminUserQuery;
use App\ApiResource\AdminUserPage;
use App\Pagination\CursorCodec;
use App\Pagination\PageRequest;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /admin/users` (ROLE_MODERATOR, ADR 013): newest accounts first, keyset on `id` DESC.
 * `q` matches username / display name like `/search?type=users`; for an admin a `q` containing
 * `@` matches the email exactly instead (a moderator never searches by email).
 *
 * @implements ProviderInterface<AdminUserPage>
 */
final readonly class AdminUserListProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AdminUserQuery $query,
        private AdminUserMapper $mapper,
        private UserRepository $users,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminUserPage
    {
        $caller = $this->currentUser->require();
        $admin = $caller->isAdmin();
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $q = AdminQueryParams::text($request, 'q', 1, 180);
        $role = AdminQueryParams::choice($request, 'role', AdminUserQuery::ROLES);
        $status = AdminQueryParams::choice($request, 'status', AdminUserQuery::STATUSES);
        $page = PageRequest::fromRequest($request, $this->codec);
        $after = null;
        if (null !== $page->after) {
            if (1 !== \count($page->after) || !\is_string($page->after[0]) || !Uuid::isValid($page->after[0])) {
                throw CursorCodec::invalid();
            }
            $after = Uuid::fromString($page->after[0]);
        }

        $ids = $this->query->pageIds($q, $admin && null !== $q && str_contains($q, '@'), $role, $status, $after, $page->limit);
        $next = null;
        if (\count($ids) > $page->limit) {
            $ids = \array_slice($ids, 0, $page->limit);
            $next = $this->codec->encode([$ids[\count($ids) - 1]]);
        }

        return new AdminUserPage(
            array_map(fn ($u) => $this->mapper->toView($u, $admin), $this->users->findByIdsOrdered($ids)),
            $next,
        );
    }
}
