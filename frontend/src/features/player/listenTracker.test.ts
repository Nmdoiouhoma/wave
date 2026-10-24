import { describe, expect, it } from 'vitest';
import { ListenTracker } from './listenTracker';

describe('ListenTracker', () => {
  it('sums continuous playback and ignores seeks', () => {
    const t = new ListenTracker();
    t.start('a');
    for (let ms = 0; ms <= 10_000; ms += 250) t.onPosition(ms); // 10 s played
    t.onDiscontinuity(); // seek
    t.onPosition(60_000);
    t.onPosition(60_500); // +0.5 s
    t.onPosition(120_000); // jump without seeking event: ignored
    expect(t.end()).toEqual({ trackId: 'a', listenedMs: 10_500 });
  });

  it('returns the session only once', () => {
    const t = new ListenTracker();
    t.start('a');
    t.onPosition(0);
    t.onPosition(1000);
    expect(t.end()?.listenedMs).toBe(1000);
    expect(t.end()).toBeNull();
  });

  it('ignores backward jumps', () => {
    const t = new ListenTracker();
    t.start('a');
    t.onPosition(5000);
    t.onPosition(1000);
    t.onPosition(2000);
    expect(t.end()?.listenedMs).toBe(1000);
  });
});
