import { createStore, useStore } from 'zustand';
import type { SessionEndReason } from '@/api/tabSync';
import type { AuthSession, Me } from '@/api/types';

export type SessionStatus = 'unknown' | 'authenticated' | 'anonymous';

export interface SessionState {
  status: SessionStatus;
  /** Access JWT, memory only (never persisted). */
  accessToken: string | null;
  /** User snapshot from the last AuthSession; live data comes from the `['me']` query. */
  user: Me | null;
  /** Why the last session ended (drives the message on the login page). */
  endReason: SessionEndReason | null;
  setSession(session: AuthSession): void;
  clearSession(reason?: SessionEndReason): void;
  setUser(user: Me): void;
}

export const sessionStore = createStore<SessionState>()((set) => ({
  status: 'unknown',
  accessToken: null,
  user: null,
  endReason: null,
  setSession: (s) =>
    set({ status: 'authenticated', accessToken: s.accessToken, user: s.user, endReason: null }),
  clearSession: (reason) =>
    set({ status: 'anonymous', accessToken: null, user: null, endReason: reason ?? null }),
  setUser: (user) => set({ user }),
}));

export function useSession<T>(selector: (s: SessionState) => T): T {
  return useStore(sessionStore, selector);
}

/** Session in the AuthSession shape, to hand over to another tab. */
export function sessionSnapshot(state: SessionState): AuthSession | null {
  if (state.status !== 'authenticated' || !state.accessToken || !state.user) return null;
  return { accessToken: state.accessToken, tokenType: 'Bearer', expiresIn: 900, user: state.user };
}
