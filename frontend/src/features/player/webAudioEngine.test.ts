import { http, HttpResponse } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { api } from '@/api/instance';
import type { ListenRequest, Track } from '@/api/types';
import { mockConfig } from '@/mocks/db';
import { server } from '@/mocks/server';
import { playerStore } from '@/stores/player';
import { FakeAudio } from '@/test/fakeAudio';
import { loginAs } from '@/test/utils';
import { WebAudioEngine } from './webAudioEngine';

const T1 = '0192f3a2-0000-7000-8000-000000000001';
const T2 = '0192f3a2-0000-7000-8000-000000000002';

function trackCalls() {
  const streamUrls: string[] = [];
  const listens: { id: string; body: ListenRequest }[] = [];
  server.events.on('request:start', async ({ request }) => {
    const path = new URL(request.url).pathname;
    if (path.endsWith('/stream-url')) streamUrls.push(path);
    const m = /\/tracks\/([^/]+)\/listens$/.exec(path);
    if (m) listens.push({ id: m[1]!, body: (await request.clone().json()) as ListenRequest });
  });
  return { streamUrls, listens };
}

async function setup() {
  await loginAs('lea@wave.local');
  const audio = new FakeAudio();
  const engine = new WebAudioEngine(audio.asAudio(), {
    fetchStreamUrl: (id) => api.tracks.streamUrl(id),
    reportListen: (id, listenedMs, keepalive) =>
      api.tracks.reportListen(id, { listenedMs, source: 'player' }, { keepalive }),
    report: (r) => playerStore.getState()._report(r),
    onEnded: () => playerStore.getState()._ended(),
    commands: { play: vi.fn(), pause: vi.fn(), next: vi.fn(), previous: vi.fn(), seek: vi.fn() },
    mediaSession: null,
  });
  playerStore.getState().attachEngine(engine);
  const tracks = await Promise.all([api.tracks.get(T1), api.tracks.get(T2)]);
  return { audio, engine, tracks: tracks as Track[] };
}

describe('WebAudioEngine (MSW + fake timers)', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-24T10:00:00Z'));
    playerStore.setState({ queue: [], index: -1, status: 'idle', positionMs: 0, error: null });
  });
  afterEach(() => {
    playerStore.getState().attachEngine(null);
    vi.useRealTimers();
  });

  it('fetches a signed URL on play and starts playback', async () => {
    const calls = trackCalls();
    const { audio, tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(audio.src).toContain('/mock-audio/'));
    expect(calls.streamUrls).toEqual([`/api/v1/tracks/${T1}/stream-url`]);
    expect(audio.paused).toBe(false);
    audio.loadMetadata(48);
    await vi.waitFor(() => expect(playerStore.getState().status).toBe('playing'));
  });

  it('on media error refetches the URL and resumes at the same position', async () => {
    const calls = trackCalls();
    const { audio, tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(audio.src).not.toBe(''));
    const firstUrl = audio.src;
    audio.loadMetadata(48);
    audio.advance(12_000); // 12 s in

    audio.fail(); // e.g. 410 stream_token_expired on the next Range request
    await vi.waitFor(() => expect(audio.src).not.toBe(firstUrl));
    expect(calls.streamUrls).toHaveLength(2);
    audio.loadMetadata(48);
    expect(audio.currentTime).toBeCloseTo(12, 0);
    expect(audio.paused).toBe(false);
    await vi.waitFor(() => expect(playerStore.getState().status).toBe('playing'));
  });

  it('gives up after one failed retry and surfaces the error', async () => {
    const { audio, tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(audio.src).not.toBe(''));
    const first = audio.src;
    audio.fail();
    await vi.waitFor(() => expect(audio.src).not.toBe(first));
    audio.fail(); // retry failed before any successful `playing`
    await vi.waitFor(() => expect(playerStore.getState().status).toBe('error'));
    expect(playerStore.getState().error).toMatch(/Lecture impossible/);
  });

  it('refetches an expired URL before resuming after a long pause', async () => {
    const calls = trackCalls();
    const { audio, tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(audio.src).not.toBe(''));
    audio.loadMetadata(48);
    audio.advance(20_000);
    playerStore.getState().pause();
    expect(audio.paused).toBe(true);

    vi.setSystemTime(Date.now() + mockConfig.streamTtlMs + 1_000); // URL expired while paused
    const before = audio.src;
    playerStore.getState().play();
    await vi.waitFor(() => expect(audio.src).not.toBe(before));
    expect(calls.streamUrls).toHaveLength(2);
    audio.loadMetadata(48);
    expect(audio.currentTime).toBeCloseTo(20, 0);
    expect(audio.paused).toBe(false);
  });

  it('reports one listen per play session when the user skips', async () => {
    const calls = trackCalls();
    const { audio, tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(audio.src).not.toBe(''));
    audio.loadMetadata(48);
    audio.advance(31_000);
    playerStore.getState().next();
    await vi.waitFor(() => expect(calls.listens).toHaveLength(1));
    expect(calls.listens[0]).toMatchObject({
      id: T1,
      body: { listenedMs: 31_000, source: 'player' },
    });
    // Second session ends with the track.
    await vi.waitFor(() => expect(playerStore.getState().index).toBe(1));
    await vi.waitFor(() => expect(audio.src).not.toBe(''));
    audio.loadMetadata(36);
    audio.advance(5_000);
    audio.emit('ended');
    await vi.waitFor(() => expect(calls.listens).toHaveLength(2));
    expect(calls.listens[1]).toMatchObject({ id: T2, body: { listenedMs: 5_000 } });
  });

  it('flushes the listen with keepalive on pagehide, once', async () => {
    const calls = trackCalls();
    const fetchSpy = vi.spyOn(globalThis, 'fetch');
    const { audio, engine, tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(audio.src).not.toBe(''));
    audio.loadMetadata(48);
    audio.advance(4_000);
    engine.flushOnPageHide();
    engine.flushOnPageHide();
    await vi.waitFor(() => expect(calls.listens).toHaveLength(1));
    expect(calls.listens[0]).toMatchObject({ body: { listenedMs: 4_000 } });
    const listenCall = fetchSpy.mock.calls.find(([url]) => String(url).endsWith('/listens'));
    expect(listenCall?.[1]?.keepalive).toBe(true);
  });

  it('shows track_not_ready as a player error', async () => {
    server.use(
      http.get('*/api/v1/tracks/:id/stream-url', () =>
        HttpResponse.json(
          {
            type: 'urn:wave:problem:track_not_ready',
            title: 'x',
            status: 409,
            code: 'track_not_ready',
          },
          { status: 409, headers: { 'Content-Type': 'application/problem+json' } },
        ),
      ),
    );
    const { tracks } = await setup();
    playerStore.getState().playTracks(tracks, 0);
    await vi.waitFor(() => expect(playerStore.getState().status).toBe('error'));
    expect(playerStore.getState().error).toMatch(/pas encore prêt/);
  });
});
