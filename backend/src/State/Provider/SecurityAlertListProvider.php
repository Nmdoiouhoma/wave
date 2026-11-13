<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Activity\AdminQuery;
use App\Activity\AlertReader;
use App\Activity\SecurityRules;
use App\ApiResource\SecurityAlertPage;
use App\Enum\AlertRule;
use App\Enum\AlertSeverity;
use App\Enum\AlertStatus;
use App\Mapper\JournalMapper;
use App\Pagination\CursorCodec;
use App\Pagination\Keyset;
use App\Pagination\PageRequest;
use App\Security\CurrentUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /admin/alerts` (ROLE_ADMIN, moderator → 403 first). `status` defaults to open; a bad
 * filter value or cursor → 400. Highest severity first, then most recent `lastSeenAt`.
 *
 * @implements ProviderInterface<SecurityAlertPage>
 */
final readonly class SecurityAlertListProvider implements ProviderInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private AlertReader $reader,
        private JournalMapper $mapper,
        private CursorCodec $codec,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SecurityAlertPage
    {
        AdminQuery::requireAdmin($this->currentUser->require());
        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : null;
        $status = AdminQuery::enum($request, 'status', AlertStatus::class) ?? AlertStatus::Open;
        $severity = AdminQuery::enum($request, 'severity', AlertSeverity::class);
        $rule = AdminQuery::enum($request, 'rule', AlertRule::class);
        $page = PageRequest::fromRequest($request, $this->codec);

        $alerts = $this->reader->page($status, $severity, $rule, self::after($page->after), $page->limit + 1);
        $next = null;
        if (\count($alerts) > $page->limit) {
            $alerts = \array_slice($alerts, 0, $page->limit);
            $last = $alerts[\count($alerts) - 1];
            $next = $this->codec->encode([SecurityRules::severityRank($last->getSeverity()), ...Keyset::fromTimestampAndId($last->getLastSeenAt(), $last->getId())]);
        }

        return new SecurityAlertPage(array_map($this->mapper->alert(...), $alerts), $next);
    }

    /**
     * Cursor keys: [severityRank, lastSeenAt, id].
     *
     * @param list<string|int|float|bool|null>|null $keys
     *
     * @return array{int, \DateTimeImmutable, \Symfony\Component\Uid\Uuid}|null
     */
    private static function after(?array $keys): ?array
    {
        if (null === $keys) {
            return null;
        }
        if (3 !== \count($keys) || !\is_int($keys[0]) || $keys[0] < 1 || $keys[0] > 3) {
            throw CursorCodec::invalid();
        }
        [$at, $id] = Keyset::timestampAndId([$keys[1], $keys[2]]);

        return [$keys[0], $at, $id];
    }
}
