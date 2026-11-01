import { createStore, useStore } from 'zustand';
import type { Track } from '@/api/types';

/** App-wide sheets opened from any track row, the player or a track page. */
interface UiState {
  trackActions: Track | null;
  addToPlaylist: Track | null;
  openTrackActions(t: Track): void;
  openAddToPlaylist(t: Track): void;
  closeSheets(): void;
}

export const uiStore = createStore<UiState>()((set) => ({
  trackActions: null,
  addToPlaylist: null,
  openTrackActions: (t) => set({ trackActions: t, addToPlaylist: null }),
  openAddToPlaylist: (t) => set({ addToPlaylist: t, trackActions: null }),
  closeSheets: () => set({ trackActions: null, addToPlaylist: null }),
}));

export function useUi<T>(selector: (s: UiState) => T): T {
  return useStore(uiStore, selector);
}
