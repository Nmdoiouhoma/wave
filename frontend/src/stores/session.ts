import { createStore, useStore } from 'zustand';
import type { AuthSession, Me } from '@/api/types';

export type SessionStatus = 'unknown' | 'authenticated' | 'anonymous';

export interface SessionState {
  status: SessionStatus;
  /** Access JWT, memory only (never persisted). */
  accessToken: string | null;
  /** User snapshot from the last AuthSession; live data comes from the `['me']` query. */
  user: Me | null;
  setSession(session: AuthSession): void;
  clearSession(): void;
  setUser(user: Me): void;
}

export const sessionStore = createStore<SessionState>()((set) => ({
  status: 'unknown',
  accessToken: null,
  user: null,
  setSession: (s) => set({ status: 'authenticated', accessToken: s.accessToken, user: s.user }),
  clearSession: () => set({ status: 'anonymous', accessToken: null, user: null }),
  setUser: (user) => set({ user }),
}));

export function useSession<T>(selector: (s: SessionState) => T): T {
  return useStore(sessionStore, selector);
}
