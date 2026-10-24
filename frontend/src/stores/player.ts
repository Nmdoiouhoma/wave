// Global player state (Zustand). UI-agnostic: playback itself is delegated to a PlayerEngine
// (web: HTMLAudioElement, see features/player/webAudioEngine.ts; RN can provide its own).
import { createStore, useStore } from 'zustand';
import { createJSONStorage, persist } from 'zustand/middleware';
import type { Track } from '@/api/types';

export type PlayerStatus = 'idle' | 'loading' | 'playing' | 'paused' | 'error';

export interface PlayerEngine {
  load(track: Track, options: { autoplay: boolean; startMs: number }): void;
  play(): void;
  pause(): void;
  seek(ms: number): void;
  setVolume(volume: number, muted: boolean): void;
  stop(): void;
}

export interface PlayerReport {
  status?: PlayerStatus;
  positionMs?: number;
  durationMs?: number;
  buffering?: boolean;
  error?: string | null;
}

/** "Previous" restarts the current track when past this position. */
export const RESTART_THRESHOLD_MS = 3_000;

export interface PlayerState {
  queue: Track[];
  index: number;
  status: PlayerStatus;
  positionMs: number;
  durationMs: number;
  buffering: boolean;
  volume: number;
  muted: boolean;
  error: string | null;

  playTracks(tracks: Track[], startIndex?: number): void;
  togglePlay(): void;
  play(): void;
  pause(): void;
  seek(ms: number): void;
  next(): void;
  previous(): void;
  jumpTo(index: number): void;
  enqueue(track: Track): void;
  removeAt(index: number): void;
  setVolume(volume: number): void;
  toggleMute(): void;
  stop(): void;

  attachEngine(engine: PlayerEngine | null): void;
  /** Engine → store. */
  _report(report: PlayerReport): void;
  _ended(): void;
}

export const isPlayable = (t: Track) => t.status === 'ready';

let engine: PlayerEngine | null = null;

export const playerStore = createStore<PlayerState>()(
  persist(
    (set, get) => {
      const loadIndex = (index: number, autoplay = true) => {
        const track = get().queue[index];
        if (!track) return;
        set({
          index,
          positionMs: 0,
          durationMs: track.durationMs ?? 0,
          status: 'loading',
          error: null,
          buffering: false,
        });
        engine?.load(track, { autoplay, startMs: 0 });
      };

      return {
        queue: [],
        index: -1,
        status: 'idle',
        positionMs: 0,
        durationMs: 0,
        buffering: false,
        volume: 0.9,
        muted: false,
        error: null,

        playTracks: (tracks, startIndex = 0) => {
          const start = tracks[startIndex];
          const queue = tracks.filter(isPlayable);
          if (!queue.length) return;
          const index = start ? Math.max(0, queue.indexOf(start)) : 0;
          set({ queue });
          loadIndex(index);
        },
        togglePlay: () => {
          const { status } = get();
          if (status === 'playing' || status === 'loading') get().pause();
          else get().play();
        },
        play: () => {
          const { index, status } = get();
          if (index < 0) return;
          if (status === 'error') return loadIndex(index);
          engine?.play();
        },
        pause: () => engine?.pause(),
        seek: (ms) => {
          const { durationMs } = get();
          const clamped = Math.max(0, durationMs ? Math.min(ms, durationMs) : ms);
          set({ positionMs: clamped });
          engine?.seek(clamped);
        },
        next: () => {
          const { index, queue } = get();
          if (index < queue.length - 1) loadIndex(index + 1);
        },
        previous: () => {
          const { index, positionMs } = get();
          if (positionMs > RESTART_THRESHOLD_MS || index <= 0) get().seek(0);
          else loadIndex(index - 1);
        },
        jumpTo: (i) => loadIndex(i),
        enqueue: (track) => {
          if (!isPlayable(track)) return;
          const { queue, index } = get();
          set({ queue: [...queue, track] });
          if (index < 0) loadIndex(queue.length);
        },
        removeAt: (i) => {
          const { queue, index } = get();
          if (i < 0 || i >= queue.length) return;
          const nextQueue = queue.filter((_, k) => k !== i);
          if (i < index) set({ queue: nextQueue, index: index - 1 });
          else if (i > index) set({ queue: nextQueue });
          else if (!nextQueue.length) get().stop();
          else {
            set({ queue: nextQueue });
            loadIndex(Math.min(i, nextQueue.length - 1), get().status === 'playing');
          }
        },
        setVolume: (volume) => {
          const v = Math.min(1, Math.max(0, volume));
          set({ volume: v, muted: v === 0 ? true : false });
          engine?.setVolume(v, v === 0);
        },
        toggleMute: () => {
          const muted = !get().muted;
          set({ muted });
          engine?.setVolume(get().volume, muted);
        },
        stop: () => {
          engine?.stop();
          set({
            queue: [],
            index: -1,
            status: 'idle',
            positionMs: 0,
            durationMs: 0,
            error: null,
            buffering: false,
          });
        },

        attachEngine: (e) => {
          engine = e;
          e?.setVolume(get().volume, get().muted);
        },
        _report: (r) => set(r),
        _ended: () => {
          const { index, queue, durationMs } = get();
          if (index < queue.length - 1) loadIndex(index + 1);
          else set({ status: 'paused', positionMs: durationMs });
        },
      };
    },
    {
      name: 'wave-player',
      storage: createJSONStorage(() => localStorage),
      partialize: (s) => ({ volume: s.volume, muted: s.muted }),
    },
  ),
);

export function usePlayer<T>(selector: (s: PlayerState) => T): T {
  return useStore(playerStore, selector);
}

export function currentTrack(s: PlayerState): Track | null {
  return s.queue[s.index] ?? null;
}
