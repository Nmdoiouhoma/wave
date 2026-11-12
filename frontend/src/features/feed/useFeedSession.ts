import { useInfiniteQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { isApiError } from '@/api/errors';
import { feedApi, type FeedSource, type Post } from './feedApi';

export const FEED_PAGE_SIZE = 10;

/**
 * One feed session (openapi `getForYouFeed` / `getFollowingFeed`): pages deduped by id, a short
 * page with a cursor fetches the next one at once, `invalid_cursor` restarts without cursor,
 * `restart()` opens a new session.
 */
export function useFeedSession(source: FeedSource) {
  const [session, setSession] = useState(0);
  const query = useInfiniteQuery({
    queryKey: ['feed', source, session],
    queryFn: async ({ pageParam }) => {
      try {
        return await feedApi.page(source, pageParam, FEED_PAGE_SIZE);
      } catch (e) {
        if (pageParam && isApiError(e) && e.code === 'invalid_cursor')
          return feedApi.page(source, null, FEED_PAGE_SIZE);
        throw e;
      }
    },
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    // A session is consumed once: never refetched behind the viewer's back, dropped on exit.
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
  });

  const { data, hasNextPage, isFetchingNextPage, isFetchNextPageError, fetchNextPage } = query;

  const posts = useMemo(() => {
    const seen = new Set<string>();
    const out: Post[] = [];
    for (const page of data?.pages ?? [])
      for (const p of page.items)
        if (!seen.has(p.id)) {
          seen.add(p.id);
          out.push(p);
        }
    return out;
  }, [data]);

  const last = data?.pages.at(-1);
  const shortPage = !!last && last.nextCursor !== null && last.items.length < FEED_PAGE_SIZE;
  useEffect(() => {
    if (shortPage && !isFetchingNextPage && !isFetchNextPageError)
      void fetchNextPage({ cancelRefetch: false });
  }, [shortPage, isFetchingNextPage, isFetchNextPageError, fetchNextPage]);

  const loadMore = useCallback(() => {
    if (hasNextPage && !isFetchingNextPage && !isFetchNextPageError)
      void fetchNextPage({ cancelRefetch: false });
  }, [hasNextPage, isFetchingNextPage, isFetchNextPageError, fetchNextPage]);

  return {
    session,
    posts,
    query,
    loadMore,
    restart: () => setSession((s) => s + 1),
  };
}
