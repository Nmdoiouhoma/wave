<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CatalogArtistListView;
use App\Catalog\CatalogFeature;
use App\Catalog\CatalogProvider;
use App\Problem\ApiProblemException;
use Symfony\Component\HttpFoundation\Request;

/**
 * `GET /catalog/artists?q=` (searchCatalogArtists) and `GET /catalog/charts/artists`
 * (listCatalogChartArtists) — ADR 014. Both return `CatalogArtistList`.
 *
 * Flag off → 404 (the provider gate; the front relies on `Me.features.catalog`). A provider
 * failure never 500s: {@see CatalogProvider} returns `available: false` with empty items.
 *
 * @implements ProviderInterface<CatalogArtistListView>
 */
final readonly class CatalogArtistListProvider implements ProviderInterface
{
    private const int LIMIT_MAX = 10;
    private const int Q_MIN = 2;
    private const int Q_MAX = 100;

    public function __construct(
        private CatalogFeature $feature,
        private CatalogProvider $catalog,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CatalogArtistListView
    {
        if (!$this->feature->enabled()) {
            throw ApiProblemException::notFound();
        }

        $request = $context['request'] ?? null;
        $request = $request instanceof Request ? $request : new Request();
        $limit = $this->limit($request);

        $result = 'listCatalogChartArtists' === $operation->getName()
            ? $this->catalog->chartArtists($limit)
            : $this->catalog->searchArtists($this->query($request), $limit);

        return new CatalogArtistListView($result->available, $result->items, $result->attribution);
    }

    private function query(Request $request): string
    {
        $q = trim($request->query->getString('q'));
        $len = mb_strlen($q);
        if ($len < self::Q_MIN || $len > self::Q_MAX) {
            throw ApiProblemException::validation('q', \sprintf('Query must be between %d and %d characters.', self::Q_MIN, self::Q_MAX));
        }

        return $q;
    }

    /** Clamped to [1, 10]; an out-of-range or non-numeric value never 500s. */
    private function limit(Request $request): int
    {
        $limit = $request->query->has('limit') ? $request->query->getInt('limit', self::LIMIT_MAX) : self::LIMIT_MAX;

        return max(1, min(self::LIMIT_MAX, $limit));
    }
}
