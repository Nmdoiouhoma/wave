<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PlaylistItemPage;
use App\ApiResource\PlaylistItemView;
use App\Entity\Track;
use App\Mapper\DateFormat;
use App\Mapper\TrackMapper;
use App\Pagination\CursorCodec;
use App\Pagination\PageRequest;
use App\Repository\PlaylistRepository;
use App\Repository\TrackRepository;
use App\Security\CurrentUser;
use App\Security\TrackAudience;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /playlists/{playlistId}/items`: by position (keyset on position, ADR 002 exception),
 * limit default 50 max 100; items whose track is not visible to the caller get `track: null`.
 * Constant queries per page whatever its size (items, tracks with relations, audience
 * memberships when a restricted track is present, likes).
 *
 * @implements ProviderInterface<PlaylistItemPage>
 */
final readonly class PlaylistItemsProvider implements ProviderInterface
{
    public function __construct(
        private PlaylistLookup $lookup,
        private PlaylistRepository $playlists,
        private TrackRepository $tracks,
        private TrackMapper $trackMapper,
        private CurrentUser $currentUser,
        private CursorCodec $codec,
        private TrackAudience $audience,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlaylistItemPage
    {
        $viewer = $this->currentUser->get();
        $playlist = $this->lookup->visible($uriVariables['playlistId'] ?? null);
        $request = $context['request'] ?? null;
        $page = PageRequest::fromRequest($request instanceof Request ? $request : null, $this->codec, 50, 100);
        $after = null;
        if (null !== $page->after) {
            if (1 !== \count($page->after) || !\is_int($page->after[0]) || $page->after[0] < 0) {
                throw CursorCodec::invalid();
            }
            $after = $page->after[0];
        }

        $rows = $this->playlists->itemsPage($playlist, $viewer, $after, $page->limit);
        $next = null;
        if (\count($rows) > $page->limit) {
            $rows = \array_slice($rows, 0, $page->limit);
            $next = $this->codec->encode([(int) $rows[\count($rows) - 1]['position']]);
        }

        $tracks = $this->tracks->findByIdsWithRelations(array_column($rows, 'track_id'));
        $seen = $this->audience->trackVisibility(array_values($tracks), $viewer);
        $visible = array_values(array_filter($tracks, static fn (Track $t): bool => $seen[$t->getId()->toRfc4122()]));
        $views = [];
        foreach ($this->trackMapper->toViews($visible, $viewer) as $view) {
            $views[$view->id] = $view;
        }

        return new PlaylistItemPage(array_map(static fn (array $row): PlaylistItemView => new PlaylistItemView(
            $row['track_id'],
            (int) $row['position'],
            DateFormat::format(new \DateTimeImmutable($row['added_at'])),
            $views[$row['track_id']] ?? null,
        ), $rows), $next);
    }
}
