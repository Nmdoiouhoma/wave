import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { api } from '../instance';
import { keys } from './keys';

export function useUserProfile(username: string) {
  return useQuery({ queryKey: keys.user(username), queryFn: () => api.users.get(username) });
}

export function useUserTracks(username: string, enabled = true) {
  return useInfiniteQuery({
    queryKey: keys.userTracks(username),
    queryFn: ({ pageParam }) => api.users.tracks(username, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    enabled,
  });
}

export function useUserArtists(username: string | undefined) {
  return useQuery({
    queryKey: keys.userArtists(username ?? ''),
    queryFn: () => api.users.artists(username!),
    enabled: !!username,
  });
}
