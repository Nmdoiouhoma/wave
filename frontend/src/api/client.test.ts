import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { server } from '@/mocks/server';
import { db } from '@/mocks/db';
import { USER_IDS } from '@/mocks/fixtures';
import { sessionStore } from '@/stores/session';
import { api, apiClient } from './instance';
import { ApiError } from './errors';
import { loginAs } from '@/test/utils';

function countRequests(pathSuffix: string) {
  const counter = { n: 0 };
  server.events.on('request:start', ({ request }) => {
    if (new URL(request.url).pathname.endsWith(pathSuffix)) counter.n++;
  });
  return counter;
}

describe('api client', () => {
  it('parses problem+json into a typed ApiError', async () => {
    const err = await api.auth
      .register({ email: 'nope', password: 'short', username: 'X', displayName: '' })
      .catch((e: unknown) => e);
    expect(err).toBeInstanceOf(ApiError);
    const e = err as ApiError;
    expect(e.status).toBe(422);
    expect(e.code).toBe('validation_failed');
    expect(e.violations.map((v) => v.propertyPath).sort()).toEqual([
      'displayName',
      'email',
      'password',
      'username',
    ]);
  });

  it('reads Retry-After on 429', async () => {
    server.use(
      http.post('*/api/v1/auth/login', () =>
        HttpResponse.json(
          {
            type: 'urn:wave:problem:rate_limited',
            title: 'Too many',
            status: 429,
            code: 'rate_limited',
          },
          {
            status: 429,
            headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '42' },
          },
        ),
      ),
    );
    const e = (await api.auth
      .login({ email: 'a@b.c', password: 'x' })
      .catch((x: unknown) => x)) as ApiError;
    expect(e.code).toBe('rate_limited');
    expect(e.retryAfter).toBe(42);
  });

  it('maps a bare nginx 413 to payload_too_large', async () => {
    server.use(
      http.patch('*/api/v1/me', () => new HttpResponse('<html>413</html>', { status: 413 })),
    );
    await loginAs('lea@wave.local');
    const e = (await api.me.update({ bio: 'x' }).catch((x: unknown) => x)) as ApiError;
    expect(e.status).toBe(413);
    expect(e.code).toBe('payload_too_large');
  });

  it('refreshes once (single flight) for concurrent token_expired and replays every request', async () => {
    await loginAs('lea@wave.local');
    // Expire the in-memory access token; the refresh "cookie" stays valid.
    sessionStore.setState({ accessToken: `mock.${USER_IDS.lea}.${Date.now() - 1000}` });
    const refreshes = countRequests('/auth/refresh');
    const results = await Promise.all([
      api.me.get(),
      api.users.get('kenji'),
      api.genres.list(),
      api.tracks.get('0192f3a2-0000-7000-8000-000000000001'),
    ]);
    expect(results[0].username).toBe('lea');
    expect(results[1].username).toBe('kenji');
    expect(refreshes.n).toBe(1);
    expect(sessionStore.getState().accessToken).not.toContain(`${Date.now() - 1000}`);
    expect(sessionStore.getState().status).toBe('authenticated');
  });

  it('logs out locally when the refresh token is rejected', async () => {
    await loginAs('lea@wave.local');
    sessionStore.setState({ accessToken: `mock.${USER_IDS.lea}.${Date.now() - 1000}` });
    db.state.cookie = null;
    const e = (await api.me.get().catch((x: unknown) => x)) as ApiError;
    expect(e.code).toBe('token_expired');
    expect(sessionStore.getState().status).toBe('anonymous');
    expect(sessionStore.getState().accessToken).toBeNull();
  });

  it('does not try to refresh on other 401 codes', async () => {
    const refreshes = countRequests('/auth/refresh');
    const e = (await api.auth
      .login({ email: 'lea@wave.local', password: 'wrong-password' })
      .catch((x: unknown) => x)) as ApiError;
    expect(e.code).toBe('invalid_credentials');
    expect(refreshes.n).toBe(0);
  });

  it('restores a session from the refresh cookie', async () => {
    await loginAs('lea@wave.local');
    sessionStore.setState({ status: 'unknown', accessToken: null, user: null });
    await expect(apiClient.refresh()).resolves.toBe(true);
    expect(sessionStore.getState().user?.username).toBe('lea');
  });

  it('multipart uploads (images) go through XHR and refresh on token_expired', async () => {
    await loginAs('lea@wave.local');
    sessionStore.setState({ accessToken: `mock.${USER_IDS.lea}.${Date.now() - 1000}` });
    const png = new File([new Uint8Array(64)], 'a.png', { type: 'image/png' });
    const me = await api.me.uploadAvatar(png);
    expect(me.username).toBe('lea');
  });
});
