<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\CatalogArtistView;
use App\Catalog\CatalogFeature;
use App\Catalog\CatalogProvider;
use App\Catalog\CatalogUnavailable;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;

/**
 * `GET /catalog/artists/{externalId}` — ADR 014. The id is validated by the route requirement
 * (`dz:[0-9]{1,15}`), so `dz:abc` or `../` never reach this provider and never cause an outbound
 * call. Flag off → 404; unknown id → 404; provider down with nothing cached → 503
 * `catalog_unavailable` with `Retry-After`. Never 500.
 *
 * @implements ProviderInterface<CatalogArtistView>
 */
final readonly class CatalogArtistProvider implements ProviderInterface
{
    private const string RETRY_AFTER = '60';

    public function __construct(
        private CatalogFeature $feature,
        private CatalogProvider $catalog,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CatalogArtistView
    {
        if (!$this->feature->enabled()) {
            throw ApiProblemException::notFound();
        }

        $externalId = (string) ($uriVariables['externalId'] ?? '');

        try {
            $artist = $this->catalog->artist($externalId);
        } catch (CatalogUnavailable $e) {
            throw new ApiProblemException(ProblemCode::CatalogUnavailable, 'The catalogue is temporarily unavailable, retry later.', [], ['Retry-After' => self::RETRY_AFTER], $e);
        }

        if (null === $artist) {
            throw ApiProblemException::notFound();
        }

        return new CatalogArtistView(
            $artist->summary->id,
            $artist->summary->name,
            $artist->summary->pictureUrl,
            $artist->summary->fanCount,
            $artist->summary->deezerUrl,
            $artist->topTracks,
            $artist->attribution,
        );
    }
}
