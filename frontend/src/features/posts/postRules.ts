import type { Track } from '@/api/types';

// W-35: client-side rules of the post composer (docs/openapi.yaml `PostUpload`, DATA_MODEL §9).
export const CLIP_MIN_MS = 15_000;
export const CLIP_MAX_MS = 60_000;
export const CLIP_STEP_MS = 1_000;
export const CAPTION_MAX = 300;
export const VISUAL_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
export const VISUAL_VIDEO_MAX_BYTES = 15 * 1024 * 1024;
export const VISUAL_VIDEO_MAX_MS = 60_000;
export const VISUAL_ACCEPT = 'image/jpeg,image/png,image/webp,video/mp4';
export const POST_POLL_INTERVAL_MS = 2_000;
export const POST_POLL_TIMEOUT_MS = 120_000;

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export const isVideo = (file: File) => file.type === 'video/mp4';

/** Longest clip a track allows: 60 s, or the whole track when shorter. */
export function maxClipDuration(trackMs: number): number {
  return Math.min(CLIP_MAX_MS, Math.floor(trackMs / CLIP_STEP_MS) * CLIP_STEP_MS);
}

/** Own track that can carry a post: ready, not hidden, at least 15 s. */
export const isPostable = (t: Pick<Track, 'status' | 'moderation' | 'durationMs'>) =>
  t.status === 'ready' && t.moderation === 'visible' && canClipDuration(t.durationMs);

export const canClipDuration = (trackMs: number | null): trackMs is number =>
  trackMs !== null && trackMs >= CLIP_MIN_MS;

/** Keeps `start + duration ≤ trackMs` (the server answers 422 `clip_out_of_range` otherwise). */
export function clampClip(
  trackMs: number,
  start: number,
  duration: number,
): { startMs: number; durationMs: number } {
  const d = Math.min(Math.max(duration, CLIP_MIN_MS), maxClipDuration(trackMs));
  const s = Math.min(Math.max(start, 0), Math.max(0, trackMs - d));
  return { startMs: s, durationMs: d };
}

/** French error message, or null when the file type and size pass (video length: `checkVideoLength`). */
export function checkVisualFile(file: File): string | null {
  if (isVideo(file)) {
    if (file.size > VISUAL_VIDEO_MAX_BYTES) return 'Vidéo trop lourde : 15 Mo maximum.';
    return null;
  }
  if (!IMAGE_TYPES.includes(file.type))
    return 'Format non pris en charge : JPEG, PNG, WebP ou une vidéo MP4.';
  if (file.size > VISUAL_IMAGE_MAX_BYTES) return 'Image trop lourde : 5 Mo maximum.';
  return null;
}

export function checkVideoLength(durationMs: number | null): string | null {
  if (durationMs === null) return 'Impossible de lire cette vidéo : choisis un autre fichier.';
  if (durationMs > VISUAL_VIDEO_MAX_MS) return 'Vidéo trop longue : 60 secondes maximum.';
  return null;
}

/** Reads the duration from the video metadata only (no decoding of the whole file). */
export function readVideoDuration(file: File): Promise<number | null> {
  return new Promise((resolve) => {
    const url = URL.createObjectURL(file);
    const video = document.createElement('video');
    const done = (ms: number | null) => {
      URL.revokeObjectURL(url);
      video.removeAttribute('src');
      resolve(ms);
    };
    video.preload = 'metadata';
    video.muted = true;
    video.onloadedmetadata = () =>
      done(Number.isFinite(video.duration) ? Math.round(video.duration * 1000) : null);
    video.onerror = () => done(null);
    video.src = url;
  });
}

export const captionLength = (text: string) => [...text].length;

/** Splits text into plain and `#hashtag` parts for highlighting (rendered as text, never HTML). */
export function splitHashtags(text: string): { text: string; tag: boolean }[] {
  const out: { text: string; tag: boolean }[] = [];
  let last = 0;
  for (const m of text.matchAll(/#[\p{L}\p{N}_]+/gu)) {
    if (m.index > last) out.push({ text: text.slice(last, m.index), tag: false });
    out.push({ text: m[0], tag: true });
    last = m.index + m[0].length;
  }
  if (last < text.length) out.push({ text: text.slice(last), tag: false });
  return out;
}
