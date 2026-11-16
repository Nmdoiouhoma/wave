import { useSyncExternalStore } from 'react';
import { playerStore } from '@/stores/player';

/**
 * W-86 (ADR 014): lightweight preview player, independent from the Wave player store.
 * It never reports a listen or asks for a grant, has no queue and no like/playlist/post action.
 * The <audio> deliberately has no `crossOrigin` (Deezer's CDN sends no CORS headers; Wave never
 * proxies previews). Only one preview plays at a time; starting one pauses Wave's own player.
 */
export interface PreviewState {
  /** Catalogue track id (`dz:…`) currently loaded, or null. */
  trackId: string | null;
  status: 'idle' | 'loading' | 'playing' | 'error';
}

export interface PreviewAudio {
  src: string;
  play(): Promise<void>;
  pause(): void;
  removeAttribute(name: string): void;
  load(): void;
  addEventListener(type: string, fn: () => void): void;
}

let factory: (() => PreviewAudio) | null = null;
/** Test seam. */
export function setPreviewAudioFactory(f: (() => PreviewAudio) | null) {
  factory = f;
  audio = null;
  state = { trackId: null, status: 'idle' };
}

let audio: PreviewAudio | null = null;
let state: PreviewState = { trackId: null, status: 'idle' };
const listeners = new Set<() => void>();
/** Called with the track id on a media error; returns a fresh URL (or null) so we retry once. */
type Refresher = (trackId: string) => Promise<string | null>;
let retried: string | null = null;
let refresher: Refresher | null = null;

function set(next: PreviewState) {
  state = next;
  listeners.forEach((l) => l());
}

function el(): PreviewAudio {
  if (audio) return audio;
  audio = factory ? factory() : (new Audio() as unknown as PreviewAudio);
  audio.addEventListener('playing', () => set({ ...state, status: 'playing' }));
  audio.addEventListener('pause', () => {
    if (state.status === 'playing') set({ ...state, status: 'idle' });
  });
  audio.addEventListener('ended', () => set({ trackId: null, status: 'idle' }));
  audio.addEventListener('error', () => void onMediaError());
  return audio;
}

async function onMediaError() {
  const id = state.trackId;
  if (!id || state.status === 'idle') return;
  if (retried !== id && refresher) {
    retried = id;
    set({ trackId: id, status: 'loading' });
    const fresh = await refresher(id).catch(() => null);
    if (state.trackId !== id) return;
    if (fresh) return start(id, fresh);
  }
  set({ trackId: id, status: 'error' });
}

function start(trackId: string, url: string) {
  const a = el();
  a.src = url;
  set({ trackId, status: 'loading' });
  a.play().catch(() => void onMediaError());
}

export const preview = {
  /** `refresh` re-fetches the artist once and yields the new previewUrl of that track. */
  toggle(trackId: string, url: string, refresh: Refresher) {
    if (state.trackId === trackId && (state.status === 'playing' || state.status === 'loading')) {
      return preview.stop();
    }
    playerStore.getState().pause(); // Wave's player yields; its state is otherwise untouched.
    refresher = refresh;
    retried = null;
    start(trackId, url);
  },
  stop() {
    audio?.pause();
    audio?.removeAttribute('src');
    audio?.load();
    set({ trackId: null, status: 'idle' });
  },
};

export function usePreview(): PreviewState {
  return useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => state,
  );
}
