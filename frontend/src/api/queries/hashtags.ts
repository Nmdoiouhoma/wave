import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { api } from '../instance';
import { keys } from './keys';

/** W-43b: `name` is lowercase, without `#` (the route normalises it). */
export function useHashtag(name: string) {
  return useQuery({
    queryKey: keys.hashtag(name),
    queryFn: () => api.hashtags.get(name),
    staleTime: 60_000,
    retry: false,
  });
}

export function useHashtagPosts(name: string, enabled: boolean) {
  return useInfiniteQuery({
    queryKey: keys.hashtagPosts(name),
    queryFn: ({ pageParam }) => api.hashtags.posts(name, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    enabled,
    staleTime: 30_000,
  });
}
