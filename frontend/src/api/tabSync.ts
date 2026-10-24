// Cross-tab coordination of the web session (ADR 008).
// Refresh tokens are single-use and a reused one revokes the whole session family, so two tabs
// must never present the same `wave_refresh` cookie concurrently. Refreshes run under a
// cross-tab lock (`navigator.locks`, BroadcastChannel election as fallback) and the resulting
// access token is shared with the other tabs over a BroadcastChannel (same origin only).
import type { AuthSession } from './types';

export type SessionEndReason = 'logout' | 'token_reused' | 'expired';

export type TabMessage =
  | { type: 'session'; session: AuthSession }
  | { type: 'logout'; reason: SessionEndReason }
  | { type: 'session-request'; id: string }
  | { type: 'session-reply'; id: string; session: AuthSession }
  | { type: 'refresh-start'; tab: string }
  | { type: 'refresh-end'; tab: string };

export interface ChannelLike {
  postMessage(message: unknown): void;
  addEventListener(type: 'message', listener: (e: MessageEvent) => void): void;
  removeEventListener(type: 'message', listener: (e: MessageEvent) => void): void;
  close?(): void;
}

export interface LocksLike {
  request<T>(name: string, callback: () => Promise<T>): Promise<T>;
}

export interface TabSync {
  /** Runs `fn` while no other tab (and no other call in this tab) holds the refresh lock. */
  withRefreshLock<T>(fn: () => Promise<T>): Promise<T>;
  publish(message: TabMessage): void;
  subscribe(handler: (message: TabMessage) => void): () => void;
  /** Asks the other tabs for their current session (used at boot to avoid a rotation). */
  askPeersForSession(timeoutMs?: number): Promise<AuthSession | null>;
  close(): void;
}

export interface TabSyncOptions {
  channel?: ChannelLike | null;
  locks?: LocksLike | null;
  tabId?: string;
  /** Fallback election window (no Web Locks). */
  electionMs?: number;
  /** Fallback: max wait for another tab's refresh to finish. */
  peerRefreshTimeoutMs?: number;
}

const LOCK_NAME = 'wave-auth-refresh';
const CHANNEL_NAME = 'wave-session';

const randomId = () => Math.random().toString(36).slice(2) + Date.now().toString(36);
const sleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));

export function defaultTabSyncOptions(): TabSyncOptions {
  const channel =
    typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(CHANNEL_NAME) : null;
  const locks =
    typeof navigator !== 'undefined' && 'locks' in navigator && navigator.locks
      ? (navigator.locks as unknown as LocksLike)
      : null;
  return { channel, locks };
}

export function createTabSync(options: TabSyncOptions = {}): TabSync {
  const { channel = null, locks = null, electionMs = 60, peerRefreshTimeoutMs = 10_000 } = options;
  const tabId = options.tabId ?? randomId();
  const handlers = new Set<(m: TabMessage) => void>();
  /** Tabs currently refreshing (fallback election), with the time we heard of them. */
  const busyPeers = new Map<string, number>();
  let localChain: Promise<unknown> = Promise.resolve();

  const onMessage = (e: MessageEvent) => {
    const m = e.data as TabMessage;
    if (!m || typeof m !== 'object' || !('type' in m)) return;
    if (m.type === 'refresh-start' && m.tab !== tabId) busyPeers.set(m.tab, Date.now());
    if (m.type === 'refresh-end') busyPeers.delete(m.tab);
    handlers.forEach((h) => h(m));
  };
  channel?.addEventListener('message', onMessage);

  const publish = (m: TabMessage) => channel?.postMessage(m);

  /** In-tab mutex (always), so one tab never runs two refreshes at once either. */
  function serial<T>(fn: () => Promise<T>): Promise<T> {
    const run = localChain.then(fn, fn);
    localChain = run.catch(() => undefined);
    return run;
  }

  async function waitForPeers(): Promise<void> {
    const deadline = Date.now() + peerRefreshTimeoutMs;
    while (busyPeers.size && Date.now() < deadline) {
      for (const [tab, since] of busyPeers) {
        if (Date.now() - since > peerRefreshTimeoutMs) busyPeers.delete(tab); // crashed tab
      }
      await sleep(25);
    }
  }

  /** BroadcastChannel election: wait for running refreshes, announce, lowest id among simultaneous candidates goes first. */
  async function electedRun<T>(fn: () => Promise<T>): Promise<T> {
    for (;;) {
      await waitForPeers();
      publish({ type: 'refresh-start', tab: tabId });
      await sleep(electionMs);
      const rivals = [...busyPeers.keys()].filter((t) => t < tabId);
      if (!rivals.length) break;
      // Lost the election: withdraw, let the winner finish, then try again.
      publish({ type: 'refresh-end', tab: tabId });
    }
    try {
      return await fn();
    } finally {
      publish({ type: 'refresh-end', tab: tabId });
    }
  }

  return {
    withRefreshLock: (fn) =>
      serial(() => {
        if (locks) return locks.request(LOCK_NAME, fn);
        if (channel) return electedRun(fn);
        return fn();
      }),
    publish,
    subscribe(handler) {
      handlers.add(handler);
      return () => handlers.delete(handler);
    },
    askPeersForSession(timeoutMs = 150) {
      if (!channel) return Promise.resolve(null);
      const id = randomId();
      return new Promise((resolve) => {
        const off = (value: AuthSession | null) => {
          clearTimeout(timer);
          handlers.delete(listener);
          resolve(value);
        };
        const listener = (m: TabMessage) => {
          if (m.type === 'session-reply' && m.id === id) off(m.session);
        };
        const timer = setTimeout(() => off(null), timeoutMs);
        handlers.add(listener);
        publish({ type: 'session-request', id });
      });
    },
    close() {
      channel?.removeEventListener('message', onMessage);
      channel?.close?.();
      handlers.clear();
    },
  };
}
