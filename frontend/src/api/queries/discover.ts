import { useQuery } from '@tanstack/react-query';
import { api } from '../instance';
import { keys } from './keys';

/** W-43: the one `/discover` query shared by Découvrir and the feed side columns (stale 5 min). */
export function useDiscover(enabled = true) {
  return useQuery({
    queryKey: keys.discover(),
    queryFn: () => api.discover.home(),
    staleTime: 5 * 60_000,
    enabled,
  });
}
