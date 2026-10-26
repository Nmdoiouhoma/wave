// ADR 009 upload flow, UI-agnostic (reusable by React Native):
// slot (POST /tracks/uploads) → form POST straight to storage → POST /tracks { uploadId, … }.
import { ApiError, isApiError } from './errors';
import type { WaveApi } from './endpoints';
import type { Track, TrackMetadata } from './types';
import { checkTrackFile, trackContentType } from '@/lib/files';

export type UploadPhase = 'preparing' | 'sending' | 'creating';

export interface UploadTrackOptions {
  onPhase?: (phase: UploadPhase) => void;
  /** 0..1 of the storage POST. */
  onProgress?: (fraction: number) => void;
  signal?: AbortSignal;
}

/** Codes after which a fresh slot (and a re-send of the file) can succeed. */
const RETRY_WITH_NEW_SLOT = ['upload_expired', 'upload_incomplete', 'storage_rejected'] as const;

export async function uploadTrackFile(
  api: WaveApi,
  file: File,
  metadata: TrackMetadata,
  { onPhase, onProgress, signal }: UploadTrackOptions = {},
): Promise<Track> {
  const problem = checkTrackFile(file);
  const contentType = trackContentType(file);
  if (problem || !contentType) {
    throw new ApiError({ status: 0, code: 'unsupported_media_type', detail: problem ?? undefined });
  }

  const attempt = async (): Promise<Track> => {
    onPhase?.('preparing');
    const slot = await api.tracks.createUpload({
      filename: file.name,
      size: file.size,
      contentType,
    });
    onPhase?.('sending');
    onProgress?.(0);
    await api.tracks.sendToStorage(slot, file, {
      signal,
      onProgress: (loaded, total) => onProgress?.(total ? loaded / total : 0),
    });
    onProgress?.(1);
    onPhase?.('creating');
    return api.tracks.create({ uploadId: slot.uploadId, ...metadata });
  };

  try {
    return await attempt();
  } catch (e) {
    // One automatic retry with a new slot (policy expired during a slow upload, object missing…).
    if (isApiError(e) && e.is(...RETRY_WITH_NEW_SLOT) && !signal?.aborted) return attempt();
    throw e;
  }
}
