// Media Session API bridge (lock screen / hardware keys). No-op where unsupported.
import type { Track } from '@/api/types';

export interface MediaSessionBridge {
  setTrack(track: Track): void;
  setPlaying(playing: boolean): void;
  setPosition(positionMs: number, durationMs: number): void;
  clear(): void;
}

interface Commands {
  play(): void;
  pause(): void;
  next(): void;
  previous(): void;
  seek(ms: number): void;
}

const SEEK_STEP_MS = 10_000;

export function createMediaSessionBridge(commands: Commands): MediaSessionBridge | null {
  if (typeof navigator === 'undefined' || !('mediaSession' in navigator)) return null;
  const ms = navigator.mediaSession;
  let lastPosition = 0;

  const handlers: [MediaSessionAction, MediaSessionActionHandler][] = [
    ['play', () => commands.play()],
    ['pause', () => commands.pause()],
    ['stop', () => commands.pause()],
    ['nexttrack', () => commands.next()],
    ['previoustrack', () => commands.previous()],
    ['seekto', (d) => d.seekTime !== undefined && commands.seek(d.seekTime * 1000)],
    [
      'seekbackward',
      (d) => commands.seek(lastPosition - (d.seekOffset ?? SEEK_STEP_MS / 1000) * 1000),
    ],
    [
      'seekforward',
      (d) => commands.seek(lastPosition + (d.seekOffset ?? SEEK_STEP_MS / 1000) * 1000),
    ],
  ];
  for (const [action, handler] of handlers) {
    try {
      ms.setActionHandler(action, handler);
    } catch {
      /* action not supported by this browser */
    }
  }

  let lastSync = 0;
  return {
    setTrack(track) {
      if (typeof MediaMetadata === 'undefined') return;
      ms.metadata = new MediaMetadata({
        title: track.title,
        artist: track.artist.name,
        album: 'Wave',
        artwork: track.coverUrl
          ? [{ src: track.coverUrl, sizes: '800x800', type: 'image/webp' }]
          : [],
      });
    },
    setPlaying(playing) {
      ms.playbackState = playing ? 'playing' : 'paused';
    },
    setPosition(positionMs, durationMs) {
      lastPosition = positionMs;
      const now = Date.now();
      if (now - lastSync < 1_000 || !durationMs || typeof ms.setPositionState !== 'function')
        return;
      lastSync = now;
      try {
        ms.setPositionState({
          duration: durationMs / 1000,
          position: Math.min(positionMs, durationMs) / 1000,
          playbackRate: 1,
        });
      } catch {
        /* invalid state during source switches */
      }
    },
    clear() {
      ms.metadata = null;
      ms.playbackState = 'none';
    },
  };
}
