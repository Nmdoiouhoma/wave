// Client-side pre-checks mirroring D4 / ADR 009 / openapi limits. The server stays authoritative.
/** MP3 / AAC / M4A. */
export const TRACK_MAX_BYTES = 31_457_280;
/** MP4 (audio only or with video — the video is discarded by the worker). */
export const TRACK_MP4_MAX_BYTES = 209_715_200;
export const IMAGE_MAX_BYTES = 5_242_880;
export const TRACK_ACCEPT =
  '.mp3,.m4a,.aac,.mp4,audio/mpeg,audio/mp4,audio/aac,audio/x-m4a,video/mp4';
export const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp';

export type TrackContentType =
  'audio/mpeg' | 'audio/mp4' | 'audio/aac' | 'audio/x-m4a' | 'video/mp4';

const EXT_TYPES: Record<string, TrackContentType> = {
  mp3: 'audio/mpeg',
  m4a: 'audio/mp4',
  aac: 'audio/aac',
  mp4: 'video/mp4',
};
const ALLOWED_TYPES: readonly string[] = [
  'audio/mpeg',
  'audio/mp4',
  'audio/aac',
  'audio/x-m4a',
  'video/mp4',
];
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

export const fileExtension = (name: string) =>
  name.includes('.') ? (name.split('.').pop()?.toLowerCase() ?? '') : '';

/** The extension decides (the server limits by extension); the browser MIME is only a fallback. */
export function trackContentType(file: File): TrackContentType | null {
  const byExt = EXT_TYPES[fileExtension(file.name)];
  if (byExt) {
    // Keep a precise browser MIME when it agrees with the extension family.
    if (
      ALLOWED_TYPES.includes(file.type) &&
      (file.type === 'video/mp4') === (byExt === 'video/mp4')
    )
      return file.type as TrackContentType;
    return byExt;
  }
  return null;
}

export function trackMaxBytes(file: File): number {
  return fileExtension(file.name) === 'mp4' ? TRACK_MP4_MAX_BYTES : TRACK_MAX_BYTES;
}

/** Returns a French error message, or null when the file passes the pre-checks. */
export function checkTrackFile(file: File): string | null {
  if (!trackContentType(file)) {
    return 'Format non pris en charge : choisis un fichier MP3, AAC/M4A ou MP4.';
  }
  if (file.size === 0) return 'Ce fichier est vide.';
  if (file.size > trackMaxBytes(file)) {
    return fileExtension(file.name) === 'mp4'
      ? 'Fichier trop lourd : 200 Mo maximum pour un MP4.'
      : 'Fichier trop lourd : 30 Mo maximum pour un MP3 ou AAC/M4A.';
  }
  return null;
}

export function checkImageFile(file: File): string | null {
  if (!IMAGE_TYPES.includes(file.type)) return 'Format non pris en charge : JPEG, PNG ou WebP.';
  if (file.size > IMAGE_MAX_BYTES) return 'Image trop lourde : 5 Mo maximum.';
  return null;
}

/** `ma_chanson-v2.mp3` → `ma chanson v2`. */
export function titleFromFilename(name: string): string {
  return name
    .replace(/\.[^.]+$/, '')
    .replace(/[_-]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, 120);
}
