<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Activity\ActivityJournal;
use App\Activity\AdminQuery;
use App\Activity\JournalReader;
use App\ApiResource\ActivityEventPage;
use App\Entity\User;
use App\Enum\ActivityCategory;
use App\Enum\ActivityType;
use App\Mapper\JournalMapper;
use App\Pagination\CursorCodec;
use App\Pagination\PageRequest;
use App\Problem\ApiProblemException;
use App\Repository\UserRepository;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `GET /admin/users/{userId}/activity` (ROLE_ADMIN). Order: moderator → 403 (before any
 * lookup) → unknown id → 404 → bad filter / cursor / limit → 400. The first page (no cursor)
 * is journaled `admin.journal_viewed`, after the read so it is not part of that page.
 *
 * @implements ProviderInterface<ActivityEventPage>
 */
final readonly class UserActivityProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private UserRepository $users,
        private JournalReader $reader,
        private JournalMapper $mapper,
        private CursorCodec $codec,
        private ActivityJournal $journal,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ActivityEventPage
    {
        $admin = AdminQuery::requireAdmin($this->currentUser->require());
        $raw = $uriVariables['userId'] ?? null;
        $user = \is_string($raw) && Uuid::isValid($raw) ? $this->users->find(Uuid::fromString($raw)) : null;
        if (!$user instanceof User) {
            throw ApiProblemException::notFound();
        }
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $category = AdminQuery::enum($request, 'category', ActivityCategory::class);
        $page = PageRequest::fromRequest($request, $this->codec);
        $after = AdminQuery::afterId($page->after);

        $events = $this->reader->userPage($user->getId(), $category, $after, $page->limit + 1);
        $next = null;
        if (\count($events) > $page->limit) {
            $events = \array_slice($events, 0, $page->limit);
            $next = $this->codec->encode([$events[\count($events) - 1]->getId()->toRfc4122()]);
        }
        $items = array_map($this->mapper->event(...), $events);

        if (null === $after) {
            $this->journal->record(ActivityType::JournalViewed, $admin, $user);
        }

        return new ActivityEventPage($items, $next);
    }
}
