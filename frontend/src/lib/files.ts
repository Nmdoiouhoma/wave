// Client-side pre-checks mirroring D4 / openapi limits. The server stays authoritative.
export const TRACK_MAX_BYTES = 31_457_280;
export const IMAGE_MAX_BYTES = 5_242_880;
export const TRACK_ACCEPT = '.mp3,.m4a,.aac,audio/mpeg,audio/mp4,audio/aac,audio/x-m4a';
export const IMAGE_ACCEPT = 'image/jpeg,image/png,image/webp';

const AUDIO_TYPES = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/aac', 'audio/x-m4a'];
const AUDIO_EXT = ['mp3', 'm4a', 'aac'];
const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const ext = (name: string) => name.split('.').pop()?.toLowerCase() ?? '';

/** Returns a French error message, or null when the file passes the pre-checks. */
export function checkTrackFile(file: File): string | null {
  if (!AUDIO_TYPES.includes(file.type) && !AUDIO_EXT.includes(ext(file.name))) {
    return 'Format non pris en charge : choisissez un fichier MP3 ou AAC/M4A.';
  }
  if (file.size > TRACK_MAX_BYTES) return 'Fichier trop lourd : 30 Mo maximum.';
  if (file.size === 0) return 'Ce fichier est vide.';
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
