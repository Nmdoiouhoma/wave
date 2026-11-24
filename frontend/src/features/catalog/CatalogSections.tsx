import { isApiError } from '@/api/errors';
import { useCatalogChart, useCatalogSearch } from '@/api/queries/catalog';
import { Alert } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { retryHint } from '@/lib/errorMessages';
import { Attribution, CatalogArtistRow } from './CatalogParts';
import type { CatalogArtistList } from '@/api/types';
import type { UseQueryResult } from '@tanstack/react-query';

const SEARCH_MIN_CHARS = 2;

function Block({
  title,
  query,
  empty,
  limit,
  unavailableNote,
}: {
  title: string;
  query: UseQueryResult<CatalogArtistList, unknown>;
  empty: string;
  limit?: number;
  /** When set, a provider failure shows this discreet note instead of nothing / an error block. */
  unavailableNote?: string;
}) {
  const { data, error } = query;
  const rateLimited = isApiError(error) && error.code === 'rate_limited';
  // `available:false` → the section is omitted (provider failure is not an error for the user).
  if (data && !data.available && !unavailableNote) return null;
  if (unavailableNote && ((data && !data.available) || (query.isError && !rateLimited)))
    return (
      <section aria-label={title} className="mt-6">
        <h2 className="text-lg font-semibold">{title}</h2>
        <p role="status" className="text-muted mt-2 text-sm">
          {unavailableNote}
        </p>
      </section>
    );
  return (
    <section aria-label={title} className="mt-6">
      <h2 className="text-lg font-semibold">{title}</h2>
      <div className="mt-2" aria-busy={query.isFetching}>
        {query.isPending ? (
          <div className="text-muted flex justify-center py-6">
            <Spinner label="Chargement…" />
          </div>
        ) : query.isError ? (
          isApiError(error) && error.code === 'rate_limited' ? (
            <Alert tone="warning">
              Trop de requêtes vers le catalogue. {retryHint(error.retryAfter)}
            </Alert>
          ) : (
            <ErrorState error={error} onRetry={() => void query.refetch()} />
          )
        ) : query.data.items.length ? (
          <ul className="space-y-2">
            {query.data.items.slice(0, limit).map((a) => (
              <CatalogArtistRow key={a.id} artist={a} />
            ))}
          </ul>
        ) : (
          <EmptyState title={empty} />
        )}
      </div>
      {data && (
        <div className="mt-2">
          <Attribution attribution={data.attribution} />
        </div>
      )}
    </section>
  );
}

/** Découvrir: « Artistes célèbres ». Only mounted when `me.features.catalog`. */
export function FamousArtistsSection({ limit }: { limit?: number }) {
  return (
    <Block
      title="Artistes célèbres"
      query={useCatalogChart(true)}
      empty="Aucun artiste pour l’instant."
      limit={limit}
    />
  );
}

/** Separate block under the search box, never mixed with Wave results. */
export function CatalogSearchBlock({ q }: { q: string }) {
  const query = useCatalogSearch(q, q.length >= SEARCH_MIN_CHARS);
  if (q.length < SEARCH_MIN_CHARS) return null;
  return (
    <Block
      title="Artistes du catalogue"
      query={query}
      empty={`Aucun artiste du catalogue pour « ${q} ».`}
      unavailableNote="Le catalogue Deezer est indisponible pour le moment."
    />
  );
}
