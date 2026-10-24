// Two simulated browser tabs sharing one cookie jar (the MSW mock backend) — ADR 008.
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { USER_IDS } from '@/mocks/fixtures';
import { server } from '@/mocks/server';
import { API_BASE_URL } from '@/lib/config';
import { createApiClient, type ApiClient } from './client';
import { createWaveApi } from './endpoints';
import type { ApiError } from './errors';
import {
  createTabSync,
  type ChannelLike,
  type LocksLike,
  type SessionEndReason,
  type TabSync,
} from './tabSync';
import type { AuthSession } from './types';

/** In-memory BroadcastChannel: async delivery to every other endpoint, never to the sender. */
function createBus() {
  const endpoints = new Set<{ deliver: (data: unknown) => void }>();
  return (): ChannelLike => {
    const listeners = new Set<(e: MessageEvent) => void>();
    const self = {
      deliver: (data: unknown) =>
        setTimeout(() => listeners.forEach((l) => l({ data } as MessageEvent)), 0),
    };
    endpoints.add(self);
    return {
      postMessage: (data) => endpoints.forEach((e) => e !== self && e.deliver(data)),
      addEventListener: (_t, l) => listeners.add(l),
      removeEventListener: (_t, l) => listeners.delete(l),
      close: () => endpoints.delete(self),
    };
  };
}

/** Shared Web Locks stand-in (exclusive, FIFO). */
function createLocks(): LocksLike {
  let chain: Promise<unknown> = Promise.resolve();
  return {
    request<T>(_name: string, cb: () => Promise<T>) {
      const run = chain.then(cb, cb);
      chain = run.catch(() => undefined);
      return run;
    },
  };
}

interface Tab {
  client: ApiClient;
  api: ReturnType<typeof createWaveApi>;
  state: { token: string | null; session: AuthSession | null; ended: SessionEndReason | null };
}

function openTab(tabSync: TabSync): Tab {
  const state: Tab['state'] = { token: null, session: null, ended: null };
  const client = createApiClient({
    baseUrl: API_BASE_URL,
    tabSync,
    session: {
      getAccessToken: () => state.token,
      getSession: () => state.session,
      setSession: (s) => Object.assign(state, { token: s.accessToken, session: s, ended: null }),
      clearSession: (reason) => Object.assign(state, { token: null, session: null, ended: reason }),
    },
  });
  return { client, api: createWaveApi(client), state };
}

function refreshMonitor() {
  const m = { total: 0, inFlight: 0, maxInFlight: 0 };
  server.events.on('request:start', ({ request }) => {
    if (!request.url.endsWith('/auth/refresh')) return;
    m.total++;
    m.inFlight++;
    m.maxInFlight = Math.max(m.maxInFlight, m.inFlight);
  });
  server.events.on('response:mocked', ({ request }) => {
    if (request.url.endsWith('/auth/refresh')) m.inFlight--;
  });
  return m;
}

/** Simulates the 15-min access token expiring in a tab (same session family). */
function expire(tab: Tab) {
  const sid = tab.state.token?.split('.')[3] ?? '';
  tab.state.token = `mock.${USER_IDS.lea}.${Date.now() - 1000}.${sid}`;
}

async function loginInBothTabs(a: Tab, b: Tab) {
  a.client.adoptSession(
    await a.api.auth.login({ email: 'lea@wave.local', password: 'motdepasse123' }),
  );
  await expect.poll(() => b.state.token).toBe(a.state.token);
}

describe.each([
  ['navigator.locks', true],
  ['BroadcastChannel election (no Web Locks)', false],
])('cross-tab refresh with %s', (_label, withLocks) => {
  it('never sends two refreshes at once and both tabs keep their session', async () => {
    const bus = createBus();
    const locks = withLocks ? createLocks() : null;
    const a = openTab(createTabSync({ channel: bus(), locks, tabId: 'a', electionMs: 20 }));
    const b = openTab(createTabSync({ channel: bus(), locks, tabId: 'b', electionMs: 20 }));
    await loginInBothTabs(a, b);
    const monitor = refreshMonitor();

    expire(a);
    expire(b);
    const [meA, meB, genresB] = await Promise.all([
      a.api.me.get(),
      b.api.me.get(),
      b.api.users.get('kenji'),
    ]);

    expect(meA.username).toBe('lea');
    expect(meB.username).toBe('lea');
    expect(genresB.username).toBe('kenji');
    expect(monitor.maxInFlight).toBe(1);
    expect(monitor.total).toBeGreaterThanOrEqual(1);
    expect(a.state.ended).toBeNull();
    expect(b.state.ended).toBeNull();
    // The session family survived: a later refresh still works.
    await expect(a.client.refresh()).resolves.toBe(true);
  });
});

describe('ADR 008 reuse detection', () => {
  it('without coordination, the concurrent refresh is seen as reuse and every tab is logged out', async () => {
    // Two tabs that cannot see each other (e.g. a client without cross-tab sync).
    const a = openTab(createTabSync());
    const b = openTab(createTabSync());
    const session = await a.api.auth.login({ email: 'lea@wave.local', password: 'motdepasse123' });
    a.client.adoptSession(session);
    b.client.adoptSession(session);
    expire(a);
    expire(b);

    const results = await Promise.allSettled([a.client.refresh(), b.client.refresh()]);
    expect(results.map((r) => r.status === 'fulfilled' && r.value).sort()).toEqual([false, true]);
    const loser = a.state.ended ? a : b;
    const winner = loser === a ? b : a;
    expect(loser.state.ended).toBe('token_reused');
    // The whole family is revoked: the "winning" tab is out at its next refresh too.
    await expect(winner.client.refresh()).resolves.toBe(false);
    expect(Object.keys(db.state.refreshTokens)).toHaveLength(0);
  });

  it('token_reused in one tab logs every tab out with that reason', async () => {
    const bus = createBus();
    const a = openTab(createTabSync({ channel: bus(), locks: createLocks() }));
    const b = openTab(createTabSync({ channel: bus(), locks: createLocks() }));
    await loginInBothTabs(a, b);
    // A stolen copy of the cookie was used first: our token is now a rotated one.
    db.state.refreshTokens[db.state.cookie!]!.rotated = true;
    expire(a);
    const err = (await a.api.me.get().catch((e: unknown) => e)) as ApiError;
    expect(err.code).toBe('token_expired');
    expect(a.state.ended).toBe('token_reused');
    await expect.poll(() => b.state.ended).toBe('token_reused');
    expect(b.state.token).toBeNull();
  });
});

describe('session sharing between tabs', () => {
  it('a new tab takes the session of an open tab instead of rotating the cookie', async () => {
    const bus = createBus();
    const a = openTab(createTabSync({ channel: bus(), locks: createLocks() }));
    a.client.adoptSession(
      await a.api.auth.login({ email: 'lea@wave.local', password: 'motdepasse123' }),
    );
    const monitor = refreshMonitor();
    const b = openTab(createTabSync({ channel: bus(), locks: createLocks() }));
    await expect(b.client.restoreSession()).resolves.toBe(true);
    expect(b.state.token).toBe(a.state.token);
    expect(monitor.total).toBe(0);
  });

  it('logout in one tab ends the session everywhere', async () => {
    const bus = createBus();
    const a = openTab(createTabSync({ channel: bus() }));
    const b = openTab(createTabSync({ channel: bus() }));
    await loginInBothTabs(a, b);
    await a.api.auth.logout();
    a.client.endSession('logout');
    await expect.poll(() => b.state.ended).toBe('logout');
  });
});

describe('POST /me/password (ADR 008)', () => {
  it('returns a rotated session for this device and revokes the other ones', async () => {
    const other = openTab(createTabSync());
    other.client.adoptSession(
      await other.api.auth.login({ email: 'lea@wave.local', password: 'motdepasse123' }),
    );
    const otherCookie = db.state.cookie!;
    const me = openTab(createTabSync());
    me.client.adoptSession(
      await me.api.auth.login({ email: 'lea@wave.local', password: 'motdepasse123' }),
    );
    const cookieBefore = db.state.cookie;

    const session = await me.api.me.changePassword({
      currentPassword: 'motdepasse123',
      newPassword: 'nouveaumotdepasse',
    });
    expect(db.state.cookie).not.toBe(cookieBefore); // rotated (new cookie set by the response)
    expect(session.accessToken.split('.')[3]).toBe(me.state.token!.split('.')[3]); // same session (sid)
    expect(session.user.username).toBe('lea');
    expect(db.state.refreshTokens[otherCookie]).toBeUndefined();
    me.client.adoptSession(session);
    await expect(me.client.refresh()).resolves.toBe(true);
  });
});
