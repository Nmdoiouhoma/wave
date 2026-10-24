import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '../instance';
import type { Artist, CreateArtistRequest, UpdateArtistRequest } from '../types';
import { keys } from './keys';

export function useArtist(slug: string) {
  return useQuery({ queryKey: keys.artist(slug), queryFn: () => api.artists.get(slug) });
}

export function useArtistTracks(slug: string) {
  return useInfiniteQuery({
    queryKey: keys.artistTracks(slug),
    queryFn: ({ pageParam }) => api.artists.tracks(slug, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });
}

function useArtistCacheSync() {
  const qc = useQueryClient();
  return (artist: Artist) => {
    qc.setQueryData(keys.artist(artist.slug), artist);
    void qc.invalidateQueries({ queryKey: keys.userArtists(artist.owner.username) });
  };
}

export function useCreateArtist() {
  const sync = useArtistCacheSync();
  return useMutation({
    mutationFn: (body: CreateArtistRequest) => api.artists.create(body),
    onSuccess: sync,
  });
}

export function useUpdateArtist(slug: string) {
  const sync = useArtistCacheSync();
  return useMutation({
    mutationFn: (body: UpdateArtistRequest) => api.artists.update(slug, body),
    onSuccess: sync,
  });
}

export function useDeleteArtist() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (artist: Artist) => api.artists.remove(artist.slug),
    onSuccess: (_d, artist) => {
      qc.removeQueries({ queryKey: keys.artist(artist.slug) });
      void qc.invalidateQueries({ queryKey: keys.userArtists(artist.owner.username) });
    },
  });
}

export function useUploadArtistAvatar(slug: string) {
  return useMutation({ mutationFn: (file: File) => api.artists.uploadAvatar(slug, file) });
}

export function useDeleteArtistAvatar(slug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api.artists.deleteAvatar(slug),
    onSuccess: () => qc.invalidateQueries({ queryKey: keys.artist(slug) }),
  });
}
