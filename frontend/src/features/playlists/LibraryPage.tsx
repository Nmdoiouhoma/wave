import { useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { useCreatePlaylist, useUserPlaylists } from '@/api/queries/playlists';
import { useLikedPlaylists, useLikedTracks } from '@/api/queries/social';
import { Button } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { HeartIcon, LibraryIcon, PlusIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Sheet } from '@/components/ui/Sheet';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { PlayAllButton, TrackList } from '@/features/tracks/TrackList';
import { toast } from '@/stores/toast';
import { PlaylistForm } from './PlaylistForm';
import { PlaylistGrid } from './PlaylistGrid';

const TABS = [
  ['playlists', 'Mes playlists'],
  ['titres', 'Titres likés'],
  ['playlists-likees', 'Playlists likées'],
] as const;
type Tab = (typeof TABS)[number][0];

export function LibraryPage() {
  const [params, setParams] = useSearchParams();
  const tab: Tab = (TABS.find(([v]) => v === params.get('onglet'))?.[0] ?? 'playlists') as Tab;
  const [creating, setCreating] = useState(false);

  return (
    <div className="mx-auto max-w-4xl">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-bold tracking-tight">Bibliothèque</h1>
        <Button icon={<PlusIcon size={16} />} onClick={() => setCreating(true)}>
          Nouvelle playlist
        </Button>
      </div>
      <div
        role="tablist"
        aria-label="Bibliothèque"
        className="mt-4 flex gap-2 overflow-x-auto pb-1"
      >
        {TABS.map(([value, label]) => (
          <button
            key={value}
            role="tab"
            id={`lib-tab-${value}`}
            aria-selected={tab === value}
            aria-controls="lib-panel"
            onClick={() =>
              setParams(value === 'playlists' ? {} : { onglet: value }, { replace: true })
            }
            className={cx(
              'shrink-0 rounded-full px-4 py-2 text-sm font-semibold ring-1 transition',
              tab === value
                ? 'bg-fg text-bg ring-fg'
                : 'bg-surface-2 text-muted ring-line hover:text-fg',
            )}
          >
            {label}
          </button>
        ))}
      </div>
      <div role="tabpanel" id="lib-panel" aria-labelledby={`lib-tab-${tab}`} className="mt-5">
        {tab === 'playlists' && <MyPlaylists onCreate={() => setCreating(true)} />}
        {tab === 'titres' && <LikedTracks />}
        {tab === 'playlists-likees' && <LikedPlaylists />}
      </div>
      <CreatePlaylistSheet open={creating} onClose={() => setCreating(false)} />
    </div>
  );
}

function CreatePlaylistSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const create = useCreatePlaylist();
  const navigate = useNavigate();
  return (
    <Sheet open={open} title="Nouvelle playlist" onClose={onClose}>
      <PlaylistForm
        submitLabel="Créer la playlist"
        pending={create.isPending}
        error={create.error}
        onSubmit={(body) =>
          create.mutate(body, {
            onSuccess: (p) => {
              onClose();
              toast(`Playlist « ${p.name} » créée`, 'success');
              void navigate(`/p/${p.id}`);
            },
          })
        }
      />
    </Sheet>
  );
}

function MyPlaylists({ onCreate }: { onCreate: () => void }) {
  const me = useCurrentUser();
  const q = useUserPlaylists(me.username);
  if (q.isPending) return <PageSpinner />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const items = q.data.pages.flatMap((p) => p.items);
  if (!items.length)
    return (
      <EmptyState icon={<LibraryIcon size={28} />} title="Aucune playlist pour l’instant">
        <p>Regroupe tes morceaux préférés, les tiens comme ceux des autres.</p>
        <Button size="sm" className="mt-3" icon={<PlusIcon size={16} />} onClick={onCreate}>
          Créer ma première playlist
        </Button>
      </EmptyState>
    );
  return (
    <>
      <PlaylistGrid playlists={items} label="Mes playlists" />
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
    </>
  );
}

function LikedTracks() {
  const q = useLikedTracks();
  if (q.isPending) return <PageSpinner />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const items = q.data.pages.flatMap((p) => p.items);
  if (!items.length)
    return (
      <EmptyState icon={<HeartIcon size={28} />} title="Aucun titre liké">
        Touche le ♥ d’un morceau pour le retrouver ici.
      </EmptyState>
    );
  return (
    <div className="flex flex-col gap-3">
      <div>
        <PlayAllButton tracks={items} />
      </div>
      <TrackList label="Titres likés" tracks={items} />
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
    </div>
  );
}

function LikedPlaylists() {
  const q = useLikedPlaylists();
  if (q.isPending) return <PageSpinner />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const items = q.data.pages.flatMap((p) => p.items);
  if (!items.length)
    return (
      <EmptyState icon={<HeartIcon size={28} />} title="Aucune playlist likée">
        Like une playlist pour la garder sous la main.
      </EmptyState>
    );
  return (
    <>
      <PlaylistGrid playlists={items} label="Playlists likées" />
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
    </>
  );
}
