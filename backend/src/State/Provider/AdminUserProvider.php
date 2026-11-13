<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Admin\AdminQueryParams;
use App\Admin\AdminUserMapper;
use App\ApiResource\AdminUserDetailView;
use App\Security\CurrentUser;

/**
 * `GET /admin/users/{userId}` (ROLE_MODERATOR): any existing account, suspended included.
 *
 * @implements ProviderInterface<AdminUserDetailView>
 */
final readonly class AdminUserProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AdminQueryParams $params,
        private AdminUserMapper $mapper,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): AdminUserDetailView
    {
        $caller = $this->currentUser->require();

        return $this->mapper->toDetail($this->params->user($uriVariables['userId'] ?? null), $caller->isAdmin());
    }
}
