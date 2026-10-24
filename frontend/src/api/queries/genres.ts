import { useQuery } from '@tanstack/react-query';
import { api } from '../instance';
import { keys } from './keys';

export function useGenres() {
  return useQuery({
    queryKey: keys.genres(),
    queryFn: () => api.genres.list(),
    staleTime: 24 * 3600_000,
  });
}
