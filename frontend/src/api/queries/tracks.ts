import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { api } from '../instance';
import type { Track, UpdateTrackRequest } from '../types';
import { keys } from './keys';

/** openapi: clients poll `GET /tracks/{id}` every 3 s and stop after 2 min. */
export const TRACK_POLL_INTERVAL_MS = 3_000;
export const TRACK_POLL_TIMEOUT_MS = 120_000;

export function useTrack(id: string, options: { initialData?: Track } = {}) {
  const [startedAt] = useState(() => Date.now());
  return useQuery({
    queryKey: keys.track(id),
    queryFn: () => api.tracks.get(id),
    initialData: options.initialData,
    refetchInterval: (query) =>
      query.state.data?.status === 'processing' && Date.now() - startedAt < TRACK_POLL_TIMEOUT_MS
        ? TRACK_POLL_INTERVAL_MS
        : false,
  });
}

function useTrackCacheSync() {
  const qc = useQueryClient();
  return (track: Track) => {
    qc.setQueryData(keys.track(track.id), track);
    void qc.invalidateQueries({ queryKey: keys.userTracks(track.owner.username) });
    void qc.invalidateQueries({ queryKey: keys.artistTracks(track.artist.slug) });
    void qc.invalidateQueries({ queryKey: keys.artist(track.artist.slug) });
  };
}

export function useUpdateTrack(id: string) {
  const sync = useTrackCacheSync();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: UpdateTrackRequest) => api.tracks.update(id, body),
    onSuccess: (track) => {
      const before = qc.getQueryData<Track>(keys.track(id));
      sync(track);
      if (before && before.artist.slug !== track.artist.slug) {
        void qc.invalidateQueries({ queryKey: keys.artistTracks(before.artist.slug) });
        void qc.invalidateQueries({ queryKey: keys.artist(before.artist.slug) });
      }
    },
  });
}

export function useDeleteTrack() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (track: Track) => api.tracks.remove(track.id),
    onSuccess: (_d, track) => {
      qc.removeQueries({ queryKey: keys.track(track.id) });
      void qc.invalidateQueries({ queryKey: keys.userTracks(track.owner.username) });
      void qc.invalidateQueries({ queryKey: keys.artistTracks(track.artist.slug) });
      void qc.invalidateQueries({ queryKey: keys.artist(track.artist.slug) });
    },
  });
}

export function useUploadTrackCover(id: string) {
  return useMutation({ mutationFn: (file: File) => api.tracks.uploadCover(id, file) });
}

export function useDeleteTrackCover(id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => api.tracks.deleteCover(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: keys.track(id) }),
  });
}

export function useInvalidateAfterUpload() {
  const qc = useQueryClient();
  return (track: Track) => {
    qc.setQueryData(keys.track(track.id), track);
    void qc.invalidateQueries({ queryKey: keys.userTracks(track.owner.username) });
    void qc.invalidateQueries({ queryKey: keys.userArtists(track.owner.username) });
    void qc.invalidateQueries({ queryKey: keys.artistTracks(track.artist.slug) });
  };
}
