// FB-18, ADR 012: the caller's single close-friends list (`/me/close-friends*`).
import {
  useInfiniteQuery,
  useMutation,
  useQuery,
  useQueryClient,
  type InfiniteData,
} from '@tanstack/react-query';
import { isApiError } from '../errors';
import { api } from '../instance';
import type { CloseFriendPage } from '../types';
import { errorMessage } from '@/lib/errorMessages';
import { toast } from '@/stores/toast';
import { keys } from './keys';

type FollowersData = InfiniteData<CloseFriendPage, string | null>;

/** Followers with their close-friend flag (picker of the close-friends screen). */
export function useCloseFriendFollowers() {
  return useInfiniteQuery({
    queryKey: keys.closeFriends('followers'),
    queryFn: ({ pageParam }) => api.me.closeFriends('followers', { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

const COUNT_PAGE = 50;

/**
 * Member count for the audience selector. No count field in the contract: one page of 50,
 * `more` = a next cursor exists (shown as "50+").
 */
export function useCloseFriendCount(enabled = true) {
  return useQuery({
    queryKey: keys.closeFriends('members'),
    queryFn: () => api.me.closeFriends('members', { limit: COUNT_PAGE }),
    select: (page) => ({ count: page.items.length, more: page.nextCursor !== null }),
    enabled,
    // The list is edited in another tab ("Gérer mes amis proches" opens one).
    refetchOnWindowFocus: 'always',
  });
}

function patchFollowers(
  data: FollowersData | undefined,
  fn: (items: CloseFriendPage['items']) => CloseFriendPage['items'],
): FollowersData | undefined {
  if (!data) return data;
  return { ...data, pages: data.pages.map((p) => ({ ...p, items: fn(p.items) })) };
}

/**
 * Optimistic toggle with rollback. 422 `not_a_follower`: the person unfollowed meanwhile,
 * the row is dropped from the list instead of rolled back.
 */
export function useToggleCloseFriend() {
  const qc = useQueryClient();
  const key = keys.closeFriends('followers');
  return useMutation({
    mutationFn: ({ username, add }: { username: string; add: boolean }) =>
      add ? api.me.addCloseFriend(username) : api.me.removeCloseFriend(username),
    onMutate: async ({ username, add }) => {
      await qc.cancelQueries({ queryKey: key });
      const previous = qc.getQueryData<FollowersData>(key);
      qc.setQueryData<FollowersData>(key, (d) =>
        patchFollowers(d, (items) =>
          items.map((u) => (u.username === username ? { ...u, isCloseFriend: add } : u)),
        ),
      );
      return { previous };
    },
    onError: (e, { username }, ctx) => {
      // not_a_follower → "Cette personne ne te suit plus."
      toast(errorMessage(e), 'error');
      if (isApiError(e) && e.code === 'not_a_follower') {
        qc.setQueryData<FollowersData>(key, (d) =>
          patchFollowers(d, (items) => items.filter((u) => u.username !== username)),
        );
        return;
      }
      qc.setQueryData(key, ctx?.previous);
    },
    onSuccess: (state, { username }) =>
      qc.setQueryData<FollowersData>(key, (d) =>
        patchFollowers(d, (items) =>
          items.map((u) =>
            u.username === username ? { ...u, isCloseFriend: state.isCloseFriend } : u,
          ),
        ),
      ),
    onSettled: () => qc.invalidateQueries({ queryKey: keys.closeFriends('members') }),
  });
}
