import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { api } from '../instance';
import type { FollowState, LikeState } from '../types';
import { socialStore, useSocial } from '@/stores/social';
import { keys } from './keys';

type LikeKind = 'track' | 'playlist';
const store = (kind: LikeKind) => (kind === 'track' ? 'trackLikes' : 'playlistLikes');

/** Effective like state: optimistic override, else the server payload. */
export function useLikeState(kind: LikeKind, id: string, server: LikeState): LikeState {
  return useSocial((s) => s[store(kind)][id]) ?? server;
}

/** Optimistic like toggle with rollback. */
export function useToggleLike(kind: LikeKind) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, like }: { id: string; like: boolean; current: LikeState }) =>
      kind === 'track'
        ? like
          ? api.tracks.like(id)
          : api.tracks.unlike(id)
        : like
          ? api.playlists.like(id)
          : api.playlists.unlike(id),
    onMutate: ({ id, like, current }) => {
      const previous = socialStore.getState()[store(kind)][id];
      socialStore.getState().set(store(kind), id, {
        liked: like,
        likeCount: Math.max(0, current.likeCount + (like ? 1 : -1)),
      });
      return { previous };
    },
    onError: (_e, { id }, ctx) => socialStore.getState().set(store(kind), id, ctx?.previous),
    onSuccess: (state, { id }) => socialStore.getState().set(store(kind), id, state),
    onSettled: () =>
      qc.invalidateQueries({
        queryKey: kind === 'track' ? keys.likedTracks() : keys.likedPlaylists(),
      }),
  });
}

export function useFollowState(username: string, server: FollowState): FollowState {
  return useSocial((s) => s.follows[username]) ?? server;
}

/** Optimistic follow toggle with rollback; the profile, lists and search share the override. */
export function useToggleFollow() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      username,
      follow,
    }: {
      username: string;
      follow: boolean;
      current: FollowState;
    }) => (follow ? api.users.follow(username) : api.users.unfollow(username)),
    onMutate: ({ username, follow, current }) => {
      const previous = socialStore.getState().follows[username];
      socialStore.getState().set('follows', username, {
        following: follow,
        followersCount: Math.max(0, current.followersCount + (follow ? 1 : -1)),
      });
      return { previous };
    },
    onError: (_e, { username }, ctx) =>
      socialStore.getState().set('follows', username, ctx?.previous),
    onSuccess: (state, { username }) => socialStore.getState().set('follows', username, state),
    onSettled: (_d, _e, { username }) => {
      // Tracks/artists/playlists visible on that profile depend on the follow (audiences).
      void qc.invalidateQueries({ queryKey: keys.user(username) });
      // My "following" list and count changed.
      const me = qc.getQueryData<{ username: string }>(keys.me());
      if (me) {
        void qc.invalidateQueries({ queryKey: keys.user(me.username) });
      }
    },
  });
}

export function useFollowList(username: string, which: 'followers' | 'following') {
  return useInfiniteQuery({
    queryKey: keys.userFollows(username, which),
    queryFn: ({ pageParam }) => api.users[which](username, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

export function useLikedTracks() {
  return useInfiniteQuery({
    queryKey: keys.likedTracks(),
    queryFn: ({ pageParam }) => api.me.likedTracks({ cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

export function useLikedPlaylists() {
  return useInfiniteQuery({
    queryKey: keys.likedPlaylists(),
    queryFn: ({ pageParam }) => api.me.likedPlaylists({ cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

export function useUserSearch(q: string) {
  const query = q.trim();
  return useInfiniteQuery({
    queryKey: keys.searchUsers(query),
    // TanStack passes an AbortSignal: a newer query cancels the previous request.
    queryFn: ({ pageParam, signal }) => api.search.users(query, { cursor: pageParam }, { signal }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    enabled: query.length >= 2,
    staleTime: 30_000,
  });
}

export function useTrackSearch(q: string) {
  const query = q.trim();
  return useInfiniteQuery({
    queryKey: keys.searchTracks(query),
    queryFn: ({ pageParam, signal }) => api.search.tracks(query, { cursor: pageParam }, { signal }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    enabled: query.length >= 2,
    staleTime: 30_000,
  });
}
