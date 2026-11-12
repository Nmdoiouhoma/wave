import { useInfiniteQuery, useQueryClient } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { isApiError } from '@/api/errors';
import { feedApi, type FeedSource, type Post } from './feedApi';

export const FEED_PAGE_SIZE = 10;
/** A left feed is kept this long, so going back to it lands on the same post (FB-24 A5). */
export const FEED_RESUME_MS = 10 * 60_000;

/** Last session and position per tab; only trusted while that session is still cached. */
const lastPosition = new Map<FeedSource, { session: number; index: number }>();

/**
 * One feed session (openapi `getForYouFeed` / `getFollowingFeed`): pages deduped by id, a short
 * page with a cursor fetches the next one at once, `invalid_cursor` restarts without cursor,
 * `restart()` opens a new session.
 */
export function useFeedSession(source: FeedSource) {
  const qc = useQueryClient();
  const [resume] = useState(() => {
    const saved = lastPosition.get(source);
    return saved && qc.getQueryData(['feed', source, saved.session]) ? saved : null;
  });
  const [session, setSession] = useState(
    () => resume?.session ?? (lastPosition.get(source)?.session ?? -1) + 1,
  );
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
    // A session is consumed once: never refetched behind the viewer's back, dropped a while after exit.
    staleTime: Infinity,
    gcTime: FEED_RESUME_MS,
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

  const setPosition = useCallback(
    (index: number) => lastPosition.set(source, { session, index }),
    [source, session],
  );

  return {
    session,
    /** Post to reopen on (0 for a new session). */
    initialIndex: resume?.session === session ? resume.index : 0,
    setPosition,
    posts,
    query,
    loadMore,
    restart: () => setSession((s) => s + 1),
  };
}
