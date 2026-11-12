import { describe, expect, it, vi } from 'vitest';
import { FakeAudio } from '@/test/fakeAudio';
import { FeedPlayback, type ClipAudio, type FeedPlaybackDeps } from './feedPlayback';
import { makePost } from './testPosts';

function setup() {
  let now = Date.parse('2026-11-12T10:00:00Z');
  const audios: FakeAudio[] = [];
  const api = {
    streamUrl: vi.fn(async (id: string) => ({
      url: `https://wave.test/stream/fresh-${id}`,
      expiresAt: new Date(now + 300_000).toISOString(),
    })),
    reportViews: vi.fn(async () => undefined),
    reportListen: vi.fn(async () => ({ counted: true, playCount: 1 })),
  };
  const deps: FeedPlaybackDeps = {
    api,
    onStatus: vi.fn(),
    onClipStart: vi.fn(),
    now: () => now,
    createAudio: () => {
      const a = new FakeAudio();
      audios.push(a);
      return a as unknown as ClipAudio;
    },
  };
  const p = new FeedPlayback(deps);
  const playing = () => audios.find((a) => !a.paused) ?? null;
  return { p, api, deps, audios, playing, advance: (ms: number) => (now += ms) };
}

const flushAsync = () => new Promise((r) => setTimeout(r, 0));

describe('FeedPlayback', () => {
  it('plays the active clip, pauses the global player, preloads and swaps to the next', async () => {
    const { p, deps, audios, playing } = setup();
    const [a, b] = [makePost(1), makePost(2)];
    p.setActive(a);
    p.preload(b);
    await flushAsync();
    expect(deps.onClipStart).toHaveBeenCalledTimes(1);
    expect(playing()?.src).toBe(a.clip!.url);
    const standby = audios.find((x) => x.src === b.clip!.url)!;
    expect(standby.paused).toBe(true);
    p.setActive(b);
    await flushAsync();
    expect(playing()).toBe(standby);
  });

  it('counts a view after 1 s, sends the feed listen on leave and the views on flush', async () => {
    const { p, api, playing, advance } = setup();
    const [a, b] = [makePost(1), makePost(2)];
    p.setActive(a);
    await flushAsync();
    playing()!.emit('playing');
    playing()!.advance(4_000);
    advance(500);
    p.tick();
    p.flush();
    expect(api.reportViews).not.toHaveBeenCalled(); // active < 1 s
    advance(4_000);
    p.setActive(b);
    expect(api.reportListen).toHaveBeenCalledWith(a, 4_000, { keepalive: false });
    p.flush(true);
    expect(api.reportViews).toHaveBeenCalledWith([{ postId: a.id, watchedMs: 4_000 }], {
      keepalive: true,
    });
  });

  it('caps the listen at the clip duration and ignores own posts for views', async () => {
    const { p, api, playing, advance } = setup();
    const own = makePost(1, { isOwner: true, clipDurationMs: 15_000 });
    p.setActive(own);
    await flushAsync();
    playing()!.emit('playing');
    playing()!.advance(20_000); // looped
    advance(20_000);
    p.setActive(null);
    expect(api.reportListen).toHaveBeenCalledWith(own, 15_000, { keepalive: false });
    p.flush();
    expect(api.reportViews).not.toHaveBeenCalled();
  });

  it('renews an expired clip URL before playing, and once more on a media error', async () => {
    const { p, api, deps, playing } = setup();
    const post = makePost(1, {
      clip: { url: 'https://wave.test/old', expiresAt: '2000-01-01T00:00:00Z' },
    });
    p.setActive(post);
    await flushAsync();
    expect(api.streamUrl).toHaveBeenCalledTimes(1);
    expect(playing()?.src).toBe(`https://wave.test/stream/fresh-${post.id}`);
    playing()!.fail();
    await flushAsync();
    expect(api.streamUrl).toHaveBeenCalledTimes(2);
    playing()!.fail();
    await flushAsync();
    expect(api.streamUrl).toHaveBeenCalledTimes(2);
    expect(deps.onStatus).toHaveBeenLastCalledWith(post.id, 'unavailable');
  });

  it('marks a post without clip unavailable', () => {
    const { p, deps } = setup();
    const post = makePost(1, { clip: null });
    p.setActive(post);
    expect(deps.onStatus).toHaveBeenLastCalledWith(post.id, 'unavailable');
  });

  it('pauses on tab hide, resumes on show, toggles on tap', async () => {
    const { p, playing } = setup();
    p.setActive(makePost(1));
    await flushAsync();
    const el = playing()!;
    p.hide();
    expect(el.paused).toBe(true);
    p.show();
    expect(el.paused).toBe(false);
    p.togglePlay();
    expect(el.paused).toBe(true);
    p.hide();
    p.show();
    expect(el.paused).toBe(true); // paused by the user: stays paused
  });

  it('reports a blocked autoplay', async () => {
    const { p, deps, audios } = setup();
    for (const a of audios)
      a.playError = Object.assign(new Error('x'), { name: 'NotAllowedError' });
    const post = makePost(1);
    p.setActive(post);
    await flushAsync();
    expect(deps.onStatus).toHaveBeenLastCalledWith(post.id, 'blocked');
  });

  it('flushes views in batches of 50 and leaves on dispose', async () => {
    const { p, api, advance } = setup();
    for (let i = 0; i < 60; i++) {
      p.setActive(makePost(i));
      advance(1_500);
    }
    p.dispose();
    expect(api.reportViews).toHaveBeenCalledTimes(2);
    const calls = api.reportViews.mock.calls as unknown as [unknown[], unknown][];
    expect(calls[0]![0]).toHaveLength(50);
    expect(calls[1]![0]).toHaveLength(10);
  });
});
