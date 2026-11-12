import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { useUserArtists, useUserProfile, useUserTracks } from '@/api/queries/users';
import { ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { EditIcon, LibraryIcon, MicIcon, MusicIcon, UploadIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { useUserPlaylists } from '@/api/queries/playlists';
import { useFollowState } from '@/api/queries/social';
import type { UserProfile } from '@/api/types';
import { PlaylistGrid } from '@/features/playlists/PlaylistGrid';
import { FollowButton } from '@/features/social/FollowButton';
import { Avatar } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { formatCount, formatMonthYear } from '@/lib/format';
import { PlayAllButton, TrackList } from '@/features/tracks/TrackList';
import { ReportButton } from '@/features/reports/ReportButton';

type Tab = 'tracks' | 'playlists' | 'artists';

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
      <header className="flex flex-col items-center gap-4 text-center sm:flex-row sm:flex-wrap sm:items-center sm:text-left">
        <Avatar
          url={u.avatarUrl}
          name={u.displayName}
          seed={u.id}
          className="ring-surface-2 size-28 shrink-0 text-3xl ring-4"
        />
        <div className="w-full min-w-0 sm:w-auto sm:min-w-56 sm:flex-1">
          <h1 className="text-3xl font-bold tracking-tight break-words">{u.displayName}</h1>
          <p className="text-muted">@{u.username}</p>
          <FollowCounts user={u} />
          <p className="text-faint mt-1 text-xs">Membre depuis {formatMonthYear(u.createdAt)}</p>
        </div>
        {u.isMe ? (
          // Settings and "Mes artistes" live here since the rail lost them (W-38).
          <div className="flex shrink-0 flex-wrap justify-center gap-2">
            <ButtonLink to="/settings" variant="secondary" size="sm">
              <EditIcon size={16} /> Modifier le profil
            </ButtonLink>
            <ButtonLink to="/me/artists" variant="secondary" size="sm">
              <MicIcon size={16} /> Mes artistes
            </ButtonLink>
          </div>
        ) : (
          <div className="flex shrink-0 items-center gap-2">
            <ReportButton target={{ type: 'user', id: u.id }} />
            <FollowButton
              username={u.username}
              displayName={u.displayName}
              server={{ following: u.isFollowedByMe, followersCount: u.followersCount }}
              size="md"
            />
          </div>
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
            ['playlists', 'Playlists'],
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
        ) : tab === 'playlists' ? (
          <UserPlaylists username={u.username} isMe={u.isMe} />
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
        title={isMe ? 'Tu n’as encore rien importé' : 'Aucun titre public'}
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

/** Counts follow the optimistic follow state (shared with search and lists). */
function FollowCounts({ user }: { user: UserProfile }) {
  const state = useFollowState(user.username, {
    following: user.isFollowedByMe,
    followersCount: user.followersCount,
  });
  return (
    <p className="mt-3 flex justify-center gap-5 text-sm sm:justify-start">
      <Link to={`/u/${user.username}/followers`} className="hover:underline">
        <strong className="font-semibold">{formatCount(state.followersCount)}</strong>{' '}
        <span className="text-muted">abonné{state.followersCount > 1 ? 's' : ''}</span>
      </Link>
      <Link to={`/u/${user.username}/following`} className="hover:underline">
        <strong className="font-semibold">{formatCount(user.followingCount)}</strong>{' '}
        <span className="text-muted">abonnement{user.followingCount > 1 ? 's' : ''}</span>
      </Link>
    </p>
  );
}

function UserPlaylists({ username, isMe }: { username: string; isMe: boolean }) {
  const q = useUserPlaylists(username);
  if (q.isPending) return <PageSpinner />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const items = q.data.pages.flatMap((p) => p.items);
  if (!items.length)
    return (
      <EmptyState
        icon={<LibraryIcon size={28} />}
        title={isMe ? 'Tu n’as pas encore de playlist' : 'Aucune playlist publique'}
      >
        {isMe && (
          <Link to="/library" className="text-accent-strong hover:underline">
            Créer une playlist dans ta bibliothèque
          </Link>
        )}
      </EmptyState>
    );
  return (
    <>
      <PlaylistGrid playlists={items} label="Playlists" />
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
    </>
  );
}
