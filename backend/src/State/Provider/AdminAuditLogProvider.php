<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Activity\AdminQuery;
use App\Activity\JournalReader;
use App\ApiResource\ActivityEventPage;
use App\Mapper\JournalMapper;
use App\Pagination\CursorCodec;
use App\Pagination\PageRequest;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /admin/audit-log` (ROLE_ADMIN, moderator → 403 first): every `admin.*` event, newest
 * first, optionally for one staff member (`actorId`, a malformed id → 400). Not journaled.
 *
 * @implements ProviderInterface<ActivityEventPage>
 */
final readonly class AdminAuditLogProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JournalReader $reader,
        private JournalMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ActivityEventPage
    {
        AdminQuery::requireAdmin($this->currentUser->require());
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $actor = AdminQuery::uuid($request, 'actorId');
        $page = PageRequest::fromRequest($request, $this->codec);

        $events = $this->reader->auditPage($actor, AdminQuery::afterId($page->after), $page->limit + 1);
        $next = null;
        if (\count($events) > $page->limit) {
            $events = \array_slice($events, 0, $page->limit);
            $next = $this->codec->encode([$events[\count($events) - 1]->getId()->toRfc4122()]);
        }

        return new ActivityEventPage(array_map($this->mapper->event(...), $events), $next);
    }
}
