import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../instance';
import { isApiError } from '../errors';
import { keys } from './keys';

/** Never retry a 404/429 (flag off, unknown id, quota): only transient failures. */
const retry = (count: number, error: unknown) =>
  count < 1 && !(isApiError(error) && [404, 422, 429, 503].includes(error.status));

export function useCatalogChart(enabled: boolean) {
  return useQuery({
    queryKey: keys.catalogChart(),
    queryFn: () => api.catalog.chartArtists(),
    enabled,
    staleTime: 10 * 60_000,
    retry,
  });
}

export function useCatalogSearch(q: string, enabled: boolean) {
  return useQuery({
    queryKey: keys.catalogSearch(q.toLowerCase()),
    queryFn: ({ signal }) => api.catalog.searchArtists(q, { signal }),
    enabled: enabled && q.length >= 2,
    staleTime: 5 * 60_000,
    retry,
  });
}

export function useCatalogArtist(externalId: string, enabled: boolean) {
  return useQuery({
    queryKey: keys.catalogArtist(externalId),
    queryFn: () => api.catalog.artist(externalId),
    enabled,
    // Preview URLs are signed and short-lived (10 min server-side).
    staleTime: 5 * 60_000,
    retry,
  });
}

/** Refetches the artist (fresh signed preview URLs); used once after a media error. */
export function useRefetchCatalogArtist(externalId: string) {
  const qc = useQueryClient();
  return () =>
    qc.fetchQuery({
      queryKey: keys.catalogArtist(externalId),
      queryFn: () => api.catalog.artist(externalId),
      staleTime: 0,
    });
}
