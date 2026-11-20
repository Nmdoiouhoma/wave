import { useRef } from 'react';
import { Link } from 'react-router';
import type { Track } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { ChevronDownIcon, PauseIcon, PlayIcon } from '@/components/ui/icons';
import { Cover } from '@/components/ui/Media';
import { LikeButton } from '@/features/social/LikeButton';
import { formatCount, formatDuration } from '@/lib/format';
import { relativeTime } from '@/features/notifications/notificationText';
import { currentTrack, playerStore, usePlayer } from '@/stores/player';
import type { RowItem } from './compose';

/** One playing track at a time, through the global player (stream-url): a second click pauses. */
function useTrackPlayback(track: Track, queue: Track[]) {
  const current = usePlayer(currentTrack);
  const status = usePlayer((s) => s.status);
  const isCurrent = current?.id === track.id;
  return {
    playing: isCurrent && (status === 'playing' || status === 'loading'),
    failed: isCurrent && status === 'error',
    toggle: () => {
      const p = playerStore.getState();
      if (isCurrent && status !== 'error') p.togglePlay();
      else p.playTracks(queue, Math.max(0, queue.indexOf(track)));
    },
  };
}

function Equalizer() {
  return (
    <span aria-hidden className="flex h-3 items-end gap-0.5">
      {[0, 0.2, 0.4].map((d) => (
        <span
          key={d}
          className="eq-bar block h-full w-[3px] rounded-sm bg-current"
          style={{ animationDelay: `${d}s` }}
        />
      ))}
    </span>
  );
}

function NowPill({ className }: { className?: string }) {
  return (
    <span
      className={cx(
        'bg-accent text-accent-contrast absolute flex items-center gap-1.5 rounded-full py-1 pr-2.5 pl-2 text-xs font-bold',
        className,
      )}
    >
      <Equalizer />
      En cours
    </span>
  );
}

function PlayFailed() {
  return (
    <p role="alert" className="text-danger mt-0.5 text-xs">
      Lecture impossible, réessaie.
    </p>
  );
}

export function CoverCard({
  item,
  queue,
  rank,
  meta,
}: {
  item: RowItem;
  queue: Track[];
  rank?: number;
  meta?: string;
}) {
  const { track } = item;
  const { playing, failed, toggle } = useTrackPlayback(track, queue);
  const label = `${playing ? 'Mettre en pause' : 'Lire'} ${track.title}, ${track.artist.name}`;
  return (
    <li className="w-36 shrink-0 snap-start md:w-44">
      <button
        type="button"
        onClick={toggle}
        aria-label={label}
        aria-pressed={playing}
        className={cx(
          'group relative mb-2.5 block aspect-square w-full overflow-hidden rounded-2xl text-white transition duration-200 motion-reduce:transition-none [@media(hover:hover)]:hover:-translate-y-1 [@media(hover:hover)]:hover:shadow-xl',
          playing && 'ring-accent ring-offset-bg ring-2 ring-offset-[3px]',
        )}
      >
        <Cover
          url={track.coverUrl}
          seed={track.id}
          className="absolute inset-0 size-full"
          rounded="rounded-none"
        />
        {rank !== undefined && (
          <span
            aria-hidden
            className="absolute top-2 left-2 flex h-[26px] min-w-[26px] items-center justify-center rounded-full bg-black/60 px-2 text-[13px] font-bold"
          >
            {rank}
          </span>
        )}
        {playing && <NowPill className="bottom-2.5 left-2.5" />}
        <span
          aria-hidden
          className={cx(
            'absolute right-2.5 bottom-2.5 flex size-10 items-center justify-center rounded-full bg-white/95 text-[#16161a] shadow-md transition duration-200 motion-reduce:transition-none',
            !playing &&
              '[@media(hover:hover)]:translate-y-1.5 [@media(hover:hover)]:opacity-0 [@media(hover:hover)]:group-hover:translate-y-0 [@media(hover:hover)]:group-hover:opacity-100 [@media(hover:hover)]:group-focus-visible:translate-y-0 [@media(hover:hover)]:group-focus-visible:opacity-100',
          )}
        >
          {playing ? <PauseIcon size={16} /> : <PlayIcon size={16} />}
        </span>
      </button>
      <Link to={`/t/${track.id}`} className="block truncate font-semibold hover:underline">
        {track.title}
      </Link>
      <Link
        to={`/a/${track.artist.slug}`}
        className="text-muted hover:text-fg block truncate text-sm hover:underline"
      >
        {track.artist.name}
      </Link>
      {item.reason && <p className="text-accent mt-0.5 line-clamp-2 text-xs">{item.reason}</p>}
      {meta && <p className="text-muted truncate text-sm">{meta}</p>}
      {failed && <PlayFailed />}
    </li>
  );
}

export type RowKind = 'for-you' | 'new' | 'trending';

const ROW_TEXT: Record<RowKind, { title: string; sub: string }> = {
  'for-you': { title: 'Pour toi', sub: 'd’après tes écoutes' },
  new: { title: 'Nouveautés', sub: 'les derniers morceaux publics' },
  trending: { title: 'En tendance', sub: 'cette semaine' },
};

export function TrackRowSection({ kind, items }: { kind: RowKind; items: RowItem[] }) {
  const list = useRef<HTMLUListElement>(null);
  const queue = items.map((i) => i.track);
  const { title, sub } = ROW_TEXT[kind];
  const scroll = (dir: -1 | 1) => {
    const el = list.current;
    if (!el) return;
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    el.scrollBy({ left: dir * el.clientWidth * 0.8, behavior: reduce ? 'auto' : 'smooth' });
  };
  return (
    <section aria-label={title} className="mt-7">
      <div className="mb-1.5 flex items-center gap-2">
        <h2 className="flex flex-wrap items-baseline gap-x-2.5 text-xl font-bold tracking-tight">
          {title}
          <span className="text-muted text-sm font-normal tracking-normal">{sub}</span>
        </h2>
        <div className="ml-auto hidden gap-1.5 md:flex">
          {([-1, 1] as const).map((d) => (
            <button
              key={d}
              type="button"
              onClick={() => scroll(d)}
              aria-label={
                d < 0 ? `${title} : défiler vers la gauche` : `${title} : défiler vers la droite`
              }
              className="border-line bg-surface hover:bg-surface-2 flex size-[34px] items-center justify-center rounded-full border"
            >
              <ChevronDownIcon size={18} className={d < 0 ? 'rotate-90' : '-rotate-90'} />
            </button>
          ))}
        </div>
      </div>
      <ul
        ref={list}
        className="-mx-4 flex snap-x snap-proximity [scrollbar-width:thin] gap-4 overflow-x-auto px-4 pt-1.5 pb-3 md:-mx-1.5 md:px-1.5"
      >
        {items.map((item, i) => (
          <CoverCard
            key={item.track.id}
            item={item}
            queue={queue}
            rank={kind === 'trending' ? i + 1 : undefined}
            meta={
              kind === 'new'
                ? relativeTime(item.track.createdAt)
                : kind === 'trending'
                  ? `${formatCount(item.track.playCount)} écoute${item.track.playCount > 1 ? 's' : ''}`
                  : undefined
            }
          />
        ))}
      </ul>
    </section>
  );
}

const KICKER = {
  'for-you': 'À la une · pour toi',
  new: 'Nouveau',
  trending: 'En tendance',
} as const;

export function Hero({ track, kind, reason }: { track: Track; kind: RowKind; reason?: string }) {
  const { playing, failed, toggle } = useTrackPlayback(track, [track]);
  const color = `hsl(${hueFromId(track.id)} 70% 55%)`;
  return (
    <section
      aria-label="Morceau mis en avant"
      style={{
        backgroundImage: `radial-gradient(120% 160% at 0% 0%, color-mix(in srgb, ${color} 30%, transparent), transparent 65%)`,
      }}
      className="border-line bg-surface flex flex-col items-center gap-5 overflow-hidden rounded-3xl border p-5 text-center md:flex-row md:gap-9 md:rounded-[28px] md:p-7 md:text-left"
    >
      <button
        type="button"
        onClick={toggle}
        aria-label={`${playing ? 'Mettre en pause' : 'Lire'} la pochette de ${track.title}`}
        tabIndex={-1}
        className={cx(
          'relative aspect-square w-full max-w-60 shrink-0 overflow-hidden rounded-3xl text-white shadow-xl transition-transform duration-300 hover:scale-[1.03] motion-reduce:transition-none motion-reduce:hover:scale-100 md:w-68 md:max-w-none',
          playing && 'shadow-2xl',
        )}
      >
        <Cover
          url={track.coverUrl}
          seed={track.id}
          className="absolute inset-0 size-full"
          rounded="rounded-none"
        />
        {playing && <NowPill className="bottom-3.5 left-3.5" />}
        <span
          aria-hidden
          className="absolute top-1/2 left-1/2 flex size-16 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-[#16161a] shadow-lg md:size-[72px]"
        >
          {playing ? <PauseIcon size={28} /> : <PlayIcon size={28} />}
        </span>
      </button>
      <div className="w-full min-w-0 flex-1 md:w-auto">
        <p className="text-accent text-xs font-bold tracking-[0.09em] uppercase">{KICKER[kind]}</p>
        <h2 className="mt-2 mb-2 text-[28px] leading-[1.05] font-bold tracking-[-0.035em] md:text-[42px]">
          <Link to={`/t/${track.id}`} className="hover:underline">
            {track.title}
          </Link>
        </h2>
        <p className="mb-2.5 text-[17px]">
          <Link to={`/a/${track.artist.slug}`} className="font-bold hover:underline">
            {track.artist.name}
          </Link>
          <span className="text-muted">
            {' '}
            · {track.genre.name} · {formatDuration(track.durationMs)}
          </span>
        </p>
        {reason && <p className="text-accent mb-4 text-sm md:mb-5">{reason}</p>}
        <div className="flex items-center gap-3 md:justify-start">
          <button
            type="button"
            onClick={toggle}
            aria-label={`${playing ? 'Pause' : 'Écouter'} ${track.title}, ${track.artist.name}`}
            className="bg-accent text-accent-contrast inline-flex h-[54px] flex-1 items-center justify-center gap-2.5 rounded-full pr-7 pl-5 text-base font-bold shadow-lg transition active:scale-[0.98] md:flex-none"
          >
            {playing ? <PauseIcon size={20} /> : <PlayIcon size={20} />}
            {playing ? 'Pause' : 'Écouter'}
          </button>
          <LikeButton
            kind="track"
            id={track.id}
            title={track.title}
            server={{ liked: track.likedByMe, likeCount: track.likeCount }}
            className="border-line bg-surface size-[54px] border"
          />
        </div>
        {failed && <PlayFailed />}
      </div>
    </section>
  );
}

function hueFromId(id: string): number {
  let h = 0;
  for (const c of id) h = (h * 31 + c.charCodeAt(0)) % 360;
  return h;
}
