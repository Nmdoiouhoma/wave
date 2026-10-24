import { Cover } from '@/components/ui/Media';
import { IconButton } from '@/components/ui/Button';
import { NextIcon } from '@/components/ui/icons';
import { currentTrack, usePlayer } from '@/stores/player';
import { PlayPauseButton } from './PlayPauseButton';
import { SeekBar } from './SeekBar';
import { VolumeControl } from './VolumeControl';

export function MiniPlayer({ onExpand }: { onExpand: () => void }) {
  const track = usePlayer(currentTrack);
  const position = usePlayer((s) => s.positionMs);
  const duration = usePlayer((s) => s.durationMs);
  const error = usePlayer((s) => s.error);
  const next = usePlayer((s) => s.next);
  const hasNext = usePlayer((s) => s.index < s.queue.length - 1);
  if (!track) return null;
  const pct = duration ? Math.min(100, (position / duration) * 100) : 0;

  return (
    <section
      aria-label="Lecteur"
      className="bg-surface-2/95 ring-line md:border-line relative mx-2 mb-2 overflow-hidden rounded-2xl shadow-2xl ring-1 shadow-black/50 backdrop-blur md:mx-0 md:mb-0 md:rounded-none md:border-t md:ring-0"
    >
      <div className="bg-surface-3 absolute inset-x-0 top-0 h-0.5 md:hidden" aria-hidden>
        <div className="bg-wave h-full" style={{ width: `${pct}%` }} />
      </div>
      <div className="flex items-center gap-3 px-3 py-2 md:px-6 md:py-3">
        <button
          type="button"
          onClick={onExpand}
          className="flex min-w-0 flex-1 items-center gap-3 text-left md:max-w-xs"
          aria-label={`Ouvrir le lecteur : ${track.title}, ${track.artist.name}`}
        >
          <Cover url={track.coverUrl} seed={track.id} className="size-11" />
          <span className="min-w-0">
            <span className="block truncate text-sm font-semibold">{track.title}</span>
            <span className="text-muted block truncate text-xs">
              {error ? <span className="text-danger">{error}</span> : track.artist.name}
            </span>
          </span>
        </button>
        <div className="hidden flex-1 flex-col items-center gap-1 md:flex">
          <SeekBar />
        </div>
        <div className="flex items-center gap-1">
          <PlayPauseButton />
          <IconButton label="Titre suivant" onClick={next} disabled={!hasNext}>
            <NextIcon size={18} />
          </IconButton>
          <div className="hidden w-36 lg:block">
            <VolumeControl />
          </div>
        </div>
      </div>
    </section>
  );
}
