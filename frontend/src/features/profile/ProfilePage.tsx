import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useUserArtists, useUserProfile, useUserTracks } from '@/api/queries/users';
import { ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { EditIcon, MicIcon, MusicIcon, UploadIcon } from '@/components/ui/icons';
import { Avatar } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { formatCount, formatMonthYear } from '@/lib/format';
import { PlayAllButton, TrackList } from '@/features/tracks/TrackList';

type Tab = 'tracks' | 'artists';

export function ProfilePage() {
  const { username = '' } = useParams();
  const profile = useUserProfile(username);
  const [tab, setTab] = useState<Tab>('tracks');

  if (profile.isPending) return <PageSpinner />;
  if (profile.isError)
    return <ErrorState error={profile.error} onRetry={() => void profile.refetch()} />;
  const u = profile.data;

  return (
    <div className="mx-auto max-w-3xl">
      <header className="flex flex-col items-center gap-4 text-center sm:flex-row sm:items-center sm:text-left">
        <Avatar
          url={u.avatarUrl}
          name={u.displayName}
          seed={u.id}
          className="ring-surface-2 size-28 text-3xl ring-4"
        />
        <div className="min-w-0 flex-1">
          <h1 className="text-3xl font-bold tracking-tight break-words">{u.displayName}</h1>
          <p className="text-muted">@{u.username}</p>
          <dl className="mt-3 flex justify-center gap-5 text-sm sm:justify-start">
            <div>
              <dt className="sr-only">Abonnés</dt>
              <dd>
                <strong className="font-semibold">{formatCount(u.followersCount)}</strong>{' '}
                <span className="text-muted">abonnés</span>
              </dd>
            </div>
            <div>
              <dt className="sr-only">Abonnements</dt>
              <dd>
                <strong className="font-semibold">{formatCount(u.followingCount)}</strong>{' '}
                <span className="text-muted">abonnements</span>
              </dd>
            </div>
          </dl>
          <p className="text-faint mt-1 text-xs">Membre depuis {formatMonthYear(u.createdAt)}</p>
        </div>
        {u.isMe && (
          <ButtonLink to="/settings" variant="secondary" size="sm">
            <EditIcon size={16} /> Modifier le profil
          </ButtonLink>
        )}
      </header>
      {u.bio && <p className="text-fg/85 mt-4 text-sm whitespace-pre-line">{u.bio}</p>}

      <div
        role="tablist"
        aria-label="Contenu du profil"
        className="border-line mt-8 flex gap-1 border-b"
      >
        {(
          [
            ['tracks', 'Titres'],
            ['artists', 'Artistes'],
          ] as const
        ).map(([value, label]) => (
          <button
            key={value}
            role="tab"
            id={`tab-${value}`}
            aria-selected={tab === value}
            aria-controls={`panel-${value}`}
            onClick={() => setTab(value)}
            className={cx(
              '-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold transition',
              tab === value
                ? 'border-accent text-fg'
                : 'text-muted hover:text-fg border-transparent',
            )}
          >
            {label}
          </button>
        ))}
      </div>
      <div role="tabpanel" id={`panel-${tab}`} aria-labelledby={`tab-${tab}`} className="mt-4">
        {tab === 'tracks' ? (
          <UserTracks username={u.username} isMe={u.isMe} />
        ) : (
          <UserArtists username={u.username} isMe={u.isMe} />
        )}
      </div>
    </div>
  );
}

function UserTracks({ username, isMe }: { username: string; isMe: boolean }) {
  const q = useUserTracks(username);
  if (q.isPending) return <PageSpinner />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const items = q.data.pages.flatMap((p) => p.items);
  if (!items.length)
    return (
      <EmptyState
        icon={<MusicIcon size={28} />}
        title={isMe ? 'Vous n’avez encore rien importé' : 'Aucun titre public'}
      >
        {isMe && (
          <ButtonLink to="/upload" size="sm" className="mt-2">
            <UploadIcon size={16} /> Importer un titre
          </ButtonLink>
        )}
      </EmptyState>
    );
  return (
    <div className="flex flex-col gap-3">
      <div>
        <PlayAllButton tracks={items} />
      </div>
      <TrackList
        label="Titres"
        tracks={items}
        hasMore={q.hasNextPage}
        loadingMore={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
    </div>
  );
}

function UserArtists({ username, isMe }: { username: string; isMe: boolean }) {
  const q = useUserArtists(username);
  if (q.isPending) return <PageSpinner />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  if (!q.data.length)
    return (
      <EmptyState icon={<MicIcon size={28} />} title="Aucun artiste">
        {isMe && (
          <Link to="/me/artists" className="text-accent-strong hover:underline">
            Créer un artiste
          </Link>
        )}
      </EmptyState>
    );
  return (
    <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3">
      {q.data.map((a) => (
        <li key={a.id}>
          <Link
            to={`/a/${a.slug}`}
            className="hover:bg-surface-2 flex flex-col items-center gap-2 rounded-2xl p-3 text-center transition"
          >
            <Avatar url={a.avatarUrl} name={a.name} seed={a.id} className="size-24 text-2xl" />
            <span className="w-full truncate font-semibold">{a.name}</span>
            <span className="text-muted text-xs">
              {a.trackCount} titre{a.trackCount > 1 ? 's' : ''}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
