import { Link } from 'react-router';
import type { Track } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { PauseIcon, PlayIcon } from '@/components/ui/icons';
import { Cover } from '@/components/ui/Media';
import { formatCount, formatDuration } from '@/lib/format';
import { currentTrack, usePlayer, isPlayable, playerStore } from '@/stores/player';
import { TrackStatusBadge } from './TrackStatusBadge';

function Equalizer() {
  return (
    <span aria-hidden className="flex h-4 items-end gap-0.5">
      {[0, 0.2, 0.4].map((d) => (
        <span
          key={d}
          className="eq-bar bg-cyan block h-full w-1 rounded-sm"
          style={{ animationDelay: `${d}s` }}
        />
      ))}
    </span>
  );
}

function TrackRow({
  track,
  index,
  tracks,
  showArtist,
}: {
  track: Track;
  index: number;
  tracks: Track[];
  showArtist: boolean;
}) {
  const current = usePlayer(currentTrack);
  const status = usePlayer((s) => s.status);
  const isCurrent = current?.id === track.id;
  const isPlaying = isCurrent && (status === 'playing' || status === 'loading');
  const playable = isPlayable(track);

  const onPlay = () => {
    if (isCurrent) playerStore.getState().togglePlay();
    else playerStore.getState().playTracks(tracks, index);
  };

  return (
    <li
      className={cx(
        'group hover:bg-surface-2 flex items-center gap-3 rounded-xl px-2 py-2 transition',
        isCurrent && 'bg-surface-2/60',
      )}
    >
      <button
        type="button"
        onClick={onPlay}
        disabled={!playable}
        aria-label={isPlaying ? `Mettre en pause ${track.title}` : `Lire ${track.title}`}
        className="relative shrink-0 disabled:cursor-not-allowed"
      >
        <Cover
          url={track.coverUrl}
          seed={track.id}
          className={cx('size-12', !playable && 'opacity-50')}
        />
        {playable && (
          <span
            className={cx(
              'absolute inset-0 flex items-center justify-center rounded-lg bg-black/45 text-white transition',
              isCurrent
                ? 'opacity-100'
                : 'opacity-0 group-focus-within:opacity-100 group-hover:opacity-100',
            )}
          >
            {isPlaying ? <PauseIcon size={18} /> : <PlayIcon size={18} />}
          </span>
        )}
      </button>
      <div className="min-w-0 flex-1">
        <div className="flex items-center gap-2">
          {isPlaying && <Equalizer />}
          <Link
            to={`/t/${track.id}`}
            className={cx(
              'truncate font-semibold hover:underline',
              isCurrent && 'text-accent-strong',
            )}
          >
            {track.title}
          </Link>
        </div>
        <div className="text-muted flex items-center gap-2 text-sm">
          {showArtist && (
            <Link to={`/a/${track.artist.slug}`} className="hover:text-fg truncate hover:underline">
              {track.artist.name}
            </Link>
          )}
          {showArtist && <span aria-hidden>·</span>}
          <span className="truncate">{track.genre.name}</span>
          <span className="sm:hidden">
            <TrackStatusBadge track={track} />
          </span>
        </div>
      </div>
      <span className="hidden sm:inline-flex">
        <TrackStatusBadge track={track} />
      </span>
      <div
        className="text-faint hidden w-16 text-right text-xs tabular-nums sm:block"
        title="Écoutes"
      >
        {track.status === 'ready' ? formatCount(track.playCount) : ''}
      </div>
      <div className="text-muted w-12 text-right text-sm tabular-nums">
        {formatDuration(track.durationMs)}
      </div>
    </li>
  );
}

export interface TrackListProps {
  tracks: Track[];
  showArtist?: boolean;
  hasMore?: boolean;
  loadingMore?: boolean;
  onLoadMore?: () => void;
  label: string;
}

export function TrackList({
  tracks,
  showArtist = true,
  hasMore,
  loadingMore,
  onLoadMore,
  label,
}: TrackListProps) {
  return (
    <div>
      <ul aria-label={label} className="flex flex-col">
        {tracks.map((t, i) => (
          <TrackRow key={t.id} track={t} index={i} tracks={tracks} showArtist={showArtist} />
        ))}
      </ul>
      {hasMore && (
        <div className="mt-3 flex justify-center">
          <Button variant="secondary" size="sm" onClick={onLoadMore} loading={loadingMore}>
            Charger plus
          </Button>
        </div>
      )}
    </div>
  );
}

export function PlayAllButton({ tracks }: { tracks: Track[] }) {
  const playable = tracks.filter(isPlayable);
  return (
    <Button
      icon={<PlayIcon size={16} />}
      onClick={() => playerStore.getState().playTracks(playable, 0)}
      disabled={!playable.length}
    >
      Tout lire
    </Button>
  );
}
