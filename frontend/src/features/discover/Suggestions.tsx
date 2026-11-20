import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { useDiscover } from '@/api/queries/discover';
import type { DiscoverHome, Hashtag, Track } from '@/api/types';
import { Button } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { errorMessage } from '@/lib/errorMessages';
import { formatCount } from '@/lib/format';
import { Cover } from '@/components/ui/Media';
import { PauseIcon, PlayIcon } from '@/components/ui/icons';
import { hashtagHref } from './hashtagHref';
import { UserList } from '@/features/social/UserList';
import { currentTrack, playerStore, usePlayer } from '@/stores/player';

export function SuggestionCard({
  title,
  children,
  more,
}: {
  title: string;
  children: ReactNode;
  more?: ReactNode;
}) {
  return (
    <section aria-label={title} className="border-line bg-surface rounded-[20px] border p-4">
      <h3 className="mb-1.5 flex items-baseline gap-2 text-[15px] font-bold">
        {title}
        {more && <span className="text-accent ml-auto text-[13px] font-medium">{more}</span>}
      </h3>
      {children}
    </section>
  );
}

export function SuggestionError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  return (
    <div role="alert">
      <p className="text-muted text-sm">{errorMessage(error)}</p>
      <Button variant="secondary" size="sm" className="mt-2.5" onClick={onRetry}>
        Réessayer
      </Button>
    </div>
  );
}

function SuggestionSkeleton() {
  return (
    <div aria-busy="true" className="text-muted flex justify-center py-6">
      <Spinner label="Chargement…" />
    </div>
  );
}

export function AccountsCard({
  users,
  compact,
  limit,
}: {
  users: DiscoverHome['suggestedUsers'];
  compact?: boolean;
  limit: number;
}) {
  return (
    <SuggestionCard title="Comptes à suivre">
      {users.length ? (
        <UserList users={users.slice(0, limit)} label="Comptes à suivre" compact={compact} />
      ) : (
        <p className="text-muted py-2 text-sm">Personne à te suggérer pour l’instant.</p>
      )}
    </SuggestionCard>
  );
}

export function HashtagChips({ tags }: { tags: Hashtag[] }) {
  return (
    <ul aria-label="Hashtags en tendance" className="mt-2 flex flex-wrap gap-2">
      {tags.map((h) => (
        <li key={h.name}>
          <Link
            to={hashtagHref(h.name)}
            className="border-line bg-surface hover:bg-surface-2 inline-flex h-9 items-center gap-1.5 rounded-full border px-3.5 font-medium whitespace-nowrap"
          >
            #{h.name}{' '}
            <span className="text-muted text-xs font-normal">{formatCount(h.postCount)}</span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

export function HashtagsCard({ tags, list }: { tags: Hashtag[]; list?: boolean }) {
  return (
    <SuggestionCard title="Hashtags en tendance">
      {!tags.length ? (
        <p className="text-muted py-2 text-sm">Aucun hashtag en tendance.</p>
      ) : list ? (
        <ol className="flex flex-col">
          {tags.map((h) => (
            <li key={h.name}>
              <Link to={hashtagHref(h.name)} className="block py-1.5">
                <span className="block truncate font-semibold">#{h.name}</span>
                <span className="text-muted text-xs">
                  {formatCount(h.postCount)} post{h.postCount > 1 ? 's' : ''}
                </span>
              </Link>
            </li>
          ))}
        </ol>
      ) : (
        <HashtagChips tags={tags} />
      )}
    </SuggestionCard>
  );
}

function MiniTrack({ track, queue }: { track: Track; queue: Track[] }) {
  const current = usePlayer(currentTrack);
  const status = usePlayer((s) => s.status);
  const playing = current?.id === track.id && (status === 'playing' || status === 'loading');
  return (
    <li className="flex items-center gap-3 py-1.5">
      <button
        type="button"
        aria-label={`${playing ? 'Mettre en pause' : 'Lire'} ${track.title}, ${track.artist.name}`}
        onClick={() => {
          const p = playerStore.getState();
          if (current?.id === track.id && status !== 'error') p.togglePlay();
          else p.playTracks(queue, queue.indexOf(track));
        }}
        className="relative shrink-0"
      >
        <Cover url={track.coverUrl} seed={track.id} className="size-11" />
        <span className="absolute inset-0 flex items-center justify-center rounded-lg bg-black/40 text-white">
          {playing ? <PauseIcon size={16} /> : <PlayIcon size={16} />}
        </span>
      </button>
      <Link to={`/t/${track.id}`} className="min-w-0">
        <span className="block truncate font-semibold">{track.title}</span>
        <span className="text-muted block truncate text-xs">{track.artist.name}</span>
      </Link>
    </li>
  );
}

function TrendingTracksCard({ tracks }: { tracks: Track[] }) {
  const playable = tracks.filter((t) => t.status === 'ready').slice(0, 4);
  if (!playable.length) return null;
  return (
    <SuggestionCard title="Morceaux en tendance">
      <ul aria-label="Morceaux en tendance">
        {playable.map((t) => (
          <MiniTrack key={t.id} track={t} queue={playable} />
        ))}
      </ul>
    </SuggestionCard>
  );
}

/**
 * Desktop side column of the feeds (FB-32). Pour toi (left, narrow): 4 accounts without counts;
 * Abonnements (right): 4 accounts, or, when the follow feed is empty and the main area already
 * lists the suggestions, hashtags + trending tracks instead. One shared `/discover` query.
 */
export function FeedSideColumn({
  side,
  withAccounts = true,
}: {
  side: 'left' | 'right';
  withAccounts?: boolean;
}) {
  const q = useDiscover();
  const narrow = side === 'left';
  return (
    <aside
      aria-label="Suggestions"
      className={
        narrow
          ? 'flex w-[232px] shrink-0 flex-col gap-3 overflow-y-auto py-4 pl-4'
          : 'flex w-80 shrink-0 flex-col gap-3 overflow-y-auto p-4'
      }
    >
      {q.isPending ? (
        <SuggestionCard title="Suggestions">
          <SuggestionSkeleton />
        </SuggestionCard>
      ) : q.isError ? (
        <SuggestionCard title="Suggestions">
          <SuggestionError error={q.error} onRetry={() => void q.refetch()} />
        </SuggestionCard>
      ) : (
        <>
          {withAccounts && (
            <AccountsCard users={q.data.suggestedUsers} compact={narrow} limit={4} />
          )}
          <HashtagsCard tags={q.data.trendingHashtags.slice(0, 5)} list />
          {!withAccounts && <TrendingTracksCard tracks={q.data.trendingTracks} />}
          <Link to="/discover" className="text-accent px-4 text-sm">
            Tout voir dans Découvrir
          </Link>
        </>
      )}
    </aside>
  );
}

/** « Abonnements » with nothing to show: the suggestions replace the empty state (FB-32). */
export function FollowSuggestionsInline() {
  const q = useDiscover();
  return (
    <div className="w-full max-w-md text-left">
      <h1 className="text-[22px] font-bold tracking-tight">Ton fil d’abonnements est vide</h1>
      <p className="text-muted mt-1.5 mb-4">
        Suis quelques comptes : leurs posts et leurs reposts arriveront ici.
      </p>
      {q.isPending ? (
        <SuggestionSkeleton />
      ) : q.isError ? (
        <SuggestionCard title="Comptes à suivre">
          <SuggestionError error={q.error} onRetry={() => void q.refetch()} />
        </SuggestionCard>
      ) : (
        <AccountsCard users={q.data.suggestedUsers} limit={6} />
      )}
    </div>
  );
}
