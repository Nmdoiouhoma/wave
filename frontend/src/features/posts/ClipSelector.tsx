// W-35: start + duration of the clip, with a live preview of the segment (track stream URL).
import { useEffect, useId, useRef, useState } from 'react';
import { api } from '@/api/instance';
import { Button } from '@/components/ui/Button';
import { PauseIcon, PlayIcon } from '@/components/ui/icons';
import { errorMessage } from '@/lib/errorMessages';
import { formatDuration } from '@/lib/format';
import { playerStore } from '@/stores/player';
import { CLIP_MIN_MS, CLIP_STEP_MS, clampClip, maxClipDuration } from './postRules';

export function ClipSelector({
  trackId,
  trackMs,
  startMs,
  durationMs,
  onChange,
}: {
  trackId: string;
  trackMs: number;
  startMs: number;
  durationMs: number;
  onChange: (next: { startMs: number; durationMs: number }) => void;
}) {
  const startId = useId();
  const durationId = useId();
  const audio = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const end = startMs + durationMs;
  const endRef = useRef(end);
  const maxDuration = maxClipDuration(trackMs);

  // Changing the segment while previewing stops the preview (the old end no longer applies).
  useEffect(() => {
    endRef.current = end;
    audio.current?.pause();
  }, [startMs, end]);

  const toggle = async () => {
    const el = audio.current;
    if (!el) return;
    if (playing) {
      el.pause();
      return;
    }
    setError(null);
    try {
      playerStore.getState().pause();
      if (!el.src || el.dataset.trackId !== trackId) {
        el.src = (await api.tracks.streamUrl(trackId)).url;
        el.dataset.trackId = trackId;
      }
      el.currentTime = startMs / 1000;
      await el.play();
    } catch (e) {
      setError(errorMessage(e));
    }
  };

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <p className="text-sm font-medium">
          Extrait : {formatDuration(startMs)} → {formatDuration(end)}
        </p>
        <Button
          size="sm"
          variant="secondary"
          onClick={toggle}
          icon={playing ? <PauseIcon size={16} /> : <PlayIcon size={16} />}
        >
          {playing ? 'Arrêter' : 'Écouter l’extrait'}
        </Button>
      </div>
      <audio
        ref={audio}
        preload="none"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => setPlaying(false)}
        onTimeUpdate={(e) => {
          const el = e.currentTarget;
          if (el.currentTime * 1000 >= endRef.current) el.pause();
        }}
      />
      {error && (
        <p role="alert" className="text-danger text-sm">
          {error}
        </p>
      )}
      <div className="flex flex-col gap-1.5">
        <label htmlFor={startId} className="text-fg/90 text-sm font-medium">
          Début de l’extrait
        </label>
        <input
          id={startId}
          type="range"
          min={0}
          max={Math.max(0, trackMs - durationMs)}
          step={CLIP_STEP_MS}
          value={startMs}
          aria-valuetext={formatDuration(startMs)}
          onChange={(e) => onChange(clampClip(trackMs, Number(e.target.value), durationMs))}
          className="accent-accent h-8 w-full"
        />
        <div className="text-faint flex justify-between text-xs" aria-hidden>
          <span>0:00</span>
          <span>{formatDuration(trackMs)}</span>
        </div>
      </div>
      <div className="flex flex-col gap-1.5">
        <label htmlFor={durationId} className="text-fg/90 text-sm font-medium">
          Durée : {Math.round(durationMs / 1000)} s
        </label>
        <input
          id={durationId}
          type="range"
          min={CLIP_MIN_MS}
          max={maxDuration}
          step={CLIP_STEP_MS}
          value={durationMs}
          aria-valuetext={`${Math.round(durationMs / 1000)} secondes`}
          onChange={(e) => onChange(clampClip(trackMs, startMs, Number(e.target.value)))}
          className="accent-accent h-8 w-full"
        />
        <div className="text-faint flex justify-between text-xs" aria-hidden>
          <span>15 s</span>
          <span>{Math.round(maxDuration / 1000)} s</span>
        </div>
      </div>
    </div>
  );
}
