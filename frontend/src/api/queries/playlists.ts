import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isApiError } from '../errors';
import { api } from '../instance';
import type {
  CreatePlaylistRequest,
  Playlist,
  PlaylistItem,
  UpdatePlaylistRequest,
} from '../types';
import { keys } from './keys';

/** Playlist items are capped at 500 (409 `playlist_full`): load them all (5 pages of 100 max). */
export async function fetchAllPlaylistItems(id: string): Promise<PlaylistItem[]> {
  const all: PlaylistItem[] = [];
  let cursor: string | null = null;
  do {
    const page = await api.playlists.items(id, { cursor, limit: 100 });
    all.push(...page.items);
    cursor = page.nextCursor;
  } while (cursor);
  return all;
}

export function usePlaylist(id: string) {
  return useQuery({ queryKey: keys.playlist(id), queryFn: () => api.playlists.get(id) });
}

export function usePlaylistItems(id: string) {
  return useQuery({ queryKey: keys.playlistItems(id), queryFn: () => fetchAllPlaylistItems(id) });
}

export function useUserPlaylists(username: string | undefined) {
  return useInfiniteQuery({
    queryKey: keys.userPlaylists(username ?? ''),
    queryFn: ({ pageParam }) => api.users.playlists(username!, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    enabled: !!username,
  });
}

function useSync() {
  const qc = useQueryClient();
  return (p: Playlist) => {
    qc.setQueryData(keys.playlist(p.id), p);
    void qc.invalidateQueries({ queryKey: keys.userPlaylists(p.owner.username) });
  };
}

export function useCreatePlaylist() {
  const sync = useSync();
  return useMutation({
    mutationFn: (body: CreatePlaylistRequest) => api.playlists.create(body),
    onSuccess: sync,
  });
}

export function useUpdatePlaylist(id: string) {
  const sync = useSync();
  return useMutation({
    mutationFn: (body: UpdatePlaylistRequest) => api.playlists.update(id, body),
    onSuccess: sync,
  });
}

export function useDeletePlaylist() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (p: Playlist) => api.playlists.remove(p.id),
    onSuccess: (_d, p) => {
      qc.removeQueries({ queryKey: keys.playlist(p.id) });
      void qc.invalidateQueries({ queryKey: keys.userPlaylists(p.owner.username) });
    },
  });
}

function useRefreshPlaylist() {
  const qc = useQueryClient();
  return (id: string, ownerUsername?: string) => {
    void qc.invalidateQueries({ queryKey: keys.playlist(id) });
    void qc.invalidateQueries({ queryKey: keys.playlistItems(id) });
    if (ownerUsername) void qc.invalidateQueries({ queryKey: keys.userPlaylists(ownerUsername) });
  };
}

export function useAddToPlaylist() {
  const refresh = useRefreshPlaylist();
  return useMutation({
    mutationFn: ({ playlist, trackId }: { playlist: Playlist; trackId: string }) =>
      api.playlists.addItem(playlist.id, trackId),
    onSuccess: (_d, { playlist }) => refresh(playlist.id, playlist.owner.username),
  });
}

export function useRemoveFromPlaylist(playlist: Playlist) {
  const qc = useQueryClient();
  const refresh = useRefreshPlaylist();
  return useMutation({
    mutationFn: (trackId: string) => api.playlists.removeItem(playlist.id, trackId),
    onMutate: (trackId) => {
      const previous = qc.getQueryData<PlaylistItem[]>(keys.playlistItems(playlist.id));
      qc.setQueryData<PlaylistItem[]>(keys.playlistItems(playlist.id), (items) =>
        items?.filter((i) => i.trackId !== trackId).map((i, position) => ({ ...i, position })),
      );
      return { previous };
    },
    onError: (_e, _t, ctx) => qc.setQueryData(keys.playlistItems(playlist.id), ctx?.previous),
    onSettled: () => refresh(playlist.id, playlist.owner.username),
  });
}

/**
 * Full-order PUT (every trackId, unavailable ones included), optimistic; on any error the previous
 * order comes back, and on 409 `playlist_order_mismatch` the list is refetched (changed elsewhere).
 */
export function useReorderPlaylist(playlistId: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (items: PlaylistItem[]) =>
      api.playlists.reorder(
        playlistId,
        items.map((i) => i.trackId),
      ),
    onMutate: async (items) => {
      await qc.cancelQueries({ queryKey: keys.playlistItems(playlistId) });
      const previous = qc.getQueryData<PlaylistItem[]>(keys.playlistItems(playlistId));
      qc.setQueryData<PlaylistItem[]>(
        keys.playlistItems(playlistId),
        items.map((i, position) => ({ ...i, position })),
      );
      return { previous };
    },
    onError: (e, _items, ctx) => {
      qc.setQueryData(keys.playlistItems(playlistId), ctx?.previous);
      if (isApiError(e) && e.code === 'playlist_order_mismatch') {
        void qc.invalidateQueries({ queryKey: keys.playlistItems(playlistId) });
      }
    },
  });
}
