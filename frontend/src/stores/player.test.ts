import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Track } from '@/api/types';
import { seedTracks } from '@/mocks/fixtures';
import { toTrack, db } from '@/mocks/db';
import { playerStore, type PlayerEngine } from './player';

function tracks(): Track[] {
  return seedTracks().map((t) => toTrack(t, db.state.users[0]!));
}

describe('player store', () => {
  let engine: { [K in keyof PlayerEngine]: ReturnType<typeof vi.fn> };
  beforeEach(() => {
    engine = {
      load: vi.fn(),
      play: vi.fn(),
      pause: vi.fn(),
      seek: vi.fn(),
      setVolume: vi.fn(),
      stop: vi.fn(),
    };
    playerStore.getState().attachEngine(engine as unknown as PlayerEngine);
    playerStore.getState().stop();
    engine.load.mockClear();
  });

  it('queues only ready tracks and starts at the clicked one', () => {
    const all = tracks();
    const clicked = all.findIndex((t) => t.title === 'Néons');
    playerStore.getState().playTracks(all, clicked);
    const s = playerStore.getState();
    expect(s.queue.every((t) => t.status === 'ready')).toBe(true);
    expect(s.queue[s.index]?.title).toBe('Néons');
    expect(engine.load).toHaveBeenCalledWith(expect.objectContaining({ title: 'Néons' }), {
      autoplay: true,
      startMs: 0,
    });
  });

  it('previous restarts the track after 3 s, otherwise goes back', () => {
    playerStore.getState().playTracks(tracks(), 1);
    playerStore.setState({ positionMs: 10_000 });
    playerStore.getState().previous();
    expect(engine.seek).toHaveBeenCalledWith(0);
    expect(playerStore.getState().index).toBe(1);
    playerStore.setState({ positionMs: 1_000 });
    playerStore.getState().previous();
    expect(playerStore.getState().index).toBe(0);
  });

  it('advances on end and stops at the end of the queue', () => {
    playerStore.getState().playTracks(tracks(), 0);
    const last = playerStore.getState().queue.length - 1;
    playerStore.getState().jumpTo(last);
    playerStore.getState()._ended();
    expect(playerStore.getState().index).toBe(last);
    expect(playerStore.getState().status).toBe('paused');
  });

  it('removing the current track plays the next one', () => {
    playerStore.getState().playTracks(tracks(), 0);
    const second = playerStore.getState().queue[1]!;
    playerStore.setState({ status: 'playing' });
    playerStore.getState().removeAt(0);
    expect(playerStore.getState().queue[playerStore.getState().index]?.id).toBe(second.id);
    expect(engine.load).toHaveBeenLastCalledWith(second, { autoplay: true, startMs: 0 });
  });

  it('clamps volume and mutes at zero', () => {
    playerStore.getState().setVolume(1.5);
    expect(playerStore.getState().volume).toBe(1);
    playerStore.getState().setVolume(0);
    expect(playerStore.getState().muted).toBe(true);
    expect(engine.setVolume).toHaveBeenLastCalledWith(0, true);
  });
});
