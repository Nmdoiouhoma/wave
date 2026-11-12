import { describe, expect, it } from 'vitest';
import { clampClip, maxClipDuration, splitHashtags } from './postRules';

describe('post rules', () => {
  it('clamps the clip inside the track', () => {
    expect(clampClip(48_000, 40_000, 30_000)).toEqual({ startMs: 18_000, durationMs: 30_000 });
    expect(clampClip(200_000, 0, 90_000)).toEqual({ startMs: 0, durationMs: 60_000 });
    expect(clampClip(20_000, 5_000, 10_000)).toEqual({ startMs: 5_000, durationMs: 15_000 });
  });
  it('caps the duration at 60 s or the track length', () => {
    expect(maxClipDuration(200_000)).toBe(60_000);
    expect(maxClipDuration(36_500)).toBe(36_000);
  });
  it('splits hashtags', () => {
    expect(splitHashtags('a #Lo_Fi b#x')).toEqual([
      { text: 'a ', tag: false },
      { text: '#Lo_Fi', tag: true },
      { text: ' b', tag: false },
      { text: '#x', tag: true },
    ]);
  });
});
