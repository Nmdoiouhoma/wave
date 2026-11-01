// Optimistic social state (likes, follows) shared by every screen showing the same entity:
// an override wins over the (possibly stale) server payload until the next refetch.
import { createStore, useStore } from 'zustand';
import type { FollowState, LikeState } from '@/api/types';

export interface SocialState {
  trackLikes: Record<string, LikeState>;
  playlistLikes: Record<string, LikeState>;
  /** By username. */
  follows: Record<string, FollowState>;
  set<K extends 'trackLikes' | 'playlistLikes' | 'follows'>(
    kind: K,
    key: string,
    value: SocialState[K][string] | undefined,
  ): void;
  reset(): void;
}

export const socialStore = createStore<SocialState>()((set) => ({
  trackLikes: {},
  playlistLikes: {},
  follows: {},
  set: (kind, key, value) =>
    set((s) => {
      const next = Object.fromEntries(Object.entries(s[kind]).filter(([k]) => k !== key)) as Record<
        string,
        unknown
      >;
      if (value !== undefined) next[key] = value;
      return { [kind]: next } as Partial<SocialState>;
    }),
  reset: () => set({ trackLikes: {}, playlistLikes: {}, follows: {} }),
}));

export function useSocial<T>(selector: (s: SocialState) => T): T {
  return useStore(socialStore, selector);
}
