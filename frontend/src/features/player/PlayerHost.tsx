import { useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef } from 'react';
import { api } from '@/api/instance';
import { keys } from '@/api/queries/keys';
import type { Track } from '@/api/types';
import { playerStore } from '@/stores/player';
import { WebAudioEngine } from './webAudioEngine';

const MAX_LISTEN_MS = 3_600_000;

function isTypingTarget(t: EventTarget | null): boolean {
  if (!(t instanceof HTMLElement)) return false;
  return t.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON', 'A'].includes(t.tagName);
}

/** Mounted once at the app root: owns the <audio> element so playback survives navigation. */
export function PlayerHost() {
  const audioRef = useRef<HTMLAudioElement>(null);
  const qc = useQueryClient();

  useEffect(() => {
    const audio = audioRef.current;
    if (!audio) return;
    const store = playerStore.getState();
    const engine = new WebAudioEngine(audio, {
      fetchStreamUrl: (id) => api.tracks.streamUrl(id),
      reportListen: async (id, listenedMs, keepalive) => {
        const result = await api.tracks.reportListen(
          id,
          { listenedMs: Math.min(MAX_LISTEN_MS, listenedMs), source: 'player' },
          { keepalive },
        );
        if (result?.counted) {
          qc.setQueryData<Track>(keys.track(id), (t) =>
            t ? { ...t, playCount: result.playCount } : t,
          );
        }
        return result;
      },
      report: (r) => playerStore.getState()._report(r),
      onEnded: () => playerStore.getState()._ended(),
      commands: {
        play: () => playerStore.getState().play(),
        pause: () => playerStore.getState().pause(),
        next: () => playerStore.getState().next(),
        previous: () => playerStore.getState().previous(),
        seek: (ms) => playerStore.getState().seek(ms),
      },
    });
    store.attachEngine(engine);

    const onPageHide = () => engine.flushOnPageHide();
    const onKey = (e: KeyboardEvent) => {
      if (e.code !== 'Space' || e.repeat || isTypingTarget(e.target)) return;
      if (playerStore.getState().index < 0) return;
      e.preventDefault();
      playerStore.getState().togglePlay();
    };
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('keydown', onKey);
    return () => {
      window.removeEventListener('pagehide', onPageHide);
      window.removeEventListener('keydown', onKey);
      playerStore.getState().attachEngine(null);
      engine.destroy();
    };
  }, [qc]);

  return <audio ref={audioRef} preload="metadata" className="hidden" />;
}
