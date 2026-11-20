import type { ReactNode } from 'react';
import { Link } from 'react-router';
import { isApiError } from '@/api/errors';
import {
  useSearchOverview,
  useSectionSearch,
  useTrackSearch,
  useUserSearch,
} from '@/api/queries/social';
import type { Artist, Hashtag, Playlist } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { SearchIcon, UserIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Avatar } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { hashtagHref } from '@/features/discover/hashtagHref';
import { PlaylistGrid } from '@/features/playlists/PlaylistGrid';
import { UserList } from '@/features/social/UserList';
import { TrackList } from '@/features/tracks/TrackList';
import { SEARCH_TABS, type SearchTab } from './searchTabs';
import { formatCount } from '@/lib/format';
import { retryHint } from '@/lib/errorMessages';

function ArtistRows({ artists, label }: { artists: Artist[]; label: string }) {
  return (
    <ul aria-label={label} className="flex flex-col">
      {artists.map((a) => (
        <li key={a.id}>
          <Link
            to={`/a/${a.slug}`}
            className="hover:bg-surface-2 flex items-center gap-3 rounded-xl px-2 py-2 transition"
          >
            <Avatar url={a.avatarUrl} name={a.name} seed={a.id} className="size-11 text-sm" />
            <span className="min-w-0">
              <span className="block truncate font-semibold">{a.name}</span>
              <span className="text-muted block truncate text-xs">
                {a.trackCount} morceau{a.trackCount > 1 ? 'x' : ''}
              </span>
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

function HashtagRows({ hashtags, label }: { hashtags: Hashtag[]; label: string }) {
  return (
    <ul aria-label={label} className="flex flex-col">
      {hashtags.map((h) => (
        <li key={h.name}>
          <Link
            to={hashtagHref(h.name)}
            className="hover:bg-surface-2 flex items-baseline justify-between gap-3 rounded-xl px-3 py-2.5 transition"
          >
            <span className="truncate font-semibold">#{h.name}</span>
            <span className="text-muted shrink-0 text-xs">
              {formatCount(h.postCount)} publication{h.postCount > 1 ? 's' : ''}
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

function Status({
  query,
  children,
}: {
  query: { isPending: boolean; isError: boolean; error: unknown; refetch: () => unknown };
  children: ReactNode;
}) {
  if (query.isPending)
    return (
      <div className="text-muted flex justify-center py-8">
        <Spinner label="Recherche en cours…" />
      </div>
    );
  if (query.isError)
    return isApiError(query.error) && query.error.code === 'rate_limited' ? (
      <Alert tone="warning">
        Tu as fait beaucoup de recherches d’un coup. {retryHint(query.error.retryAfter)}
      </Alert>
    ) : (
      <ErrorState error={query.error} onRetry={() => void query.refetch()} />
    );
  return <>{children}</>;
}

const Empty = ({ q, what }: { q: string; what: string }) => (
  <EmptyState icon={<SearchIcon size={28} />} title={`${what} ne correspond à « ${q} »`}>
    Vérifie l’orthographe ou essaie un autre mot.
  </EmptyState>
);

function More({
  query,
}: {
  query: { hasNextPage: boolean; isFetchingNextPage: boolean; fetchNextPage: () => unknown };
}) {
  return (
    <InfiniteSentinel
      hasMore={query.hasNextPage}
      loading={query.isFetchingNextPage}
      onLoadMore={() => void query.fetchNextPage()}
    />
  );
}

function Overview({ q, onTab }: { q: string; onTab: (t: SearchTab) => void }) {
  const search = useSearchOverview(q);
  const r = search.data;
  const sections: { id: SearchTab; title: string; node: ReactNode; more: boolean }[] = [];
  if (r?.users?.items.length)
    sections.push({
      id: 'users',
      title: 'Personnes',
      node: <UserList users={r.users.items} label={`Personnes pour « ${q} »`} />,
      more: !!r.users.nextCursor,
    });
  if (r?.tracks?.items.length)
    sections.push({
      id: 'tracks',
      title: 'Morceaux',
      node: <TrackList tracks={r.tracks.items} label={`Morceaux pour « ${q} »`} />,
      more: !!r.tracks.nextCursor,
    });
  if (r?.artists?.items.length)
    sections.push({
      id: 'artists',
      title: 'Artistes',
      node: <ArtistRows artists={r.artists.items} label={`Artistes pour « ${q} »`} />,
      more: !!r.artists.nextCursor,
    });
  if (r?.playlists?.items.length)
    sections.push({
      id: 'playlists',
      title: 'Playlists',
      node: <PlaylistGrid playlists={r.playlists.items} label={`Playlists pour « ${q} »`} />,
      more: !!r.playlists.nextCursor,
    });
  if (r?.hashtags?.items.length)
    sections.push({
      id: 'hashtags',
      title: 'Hashtags',
      node: <HashtagRows hashtags={r.hashtags.items} label={`Hashtags pour « ${q} »`} />,
      more: !!r.hashtags.nextCursor,
    });
  return (
    <Status query={search}>
      {sections.length ? (
        <div className="flex flex-col gap-6">
          {sections.map((s) => (
            <section key={s.id} aria-label={s.title}>
              <div className="mb-1 flex items-center justify-between px-1">
                <h2 className="text-lg font-bold">{s.title}</h2>
                {s.more && (
                  <Button variant="ghost" size="sm" onClick={() => onTab(s.id)}>
                    Voir tout
                  </Button>
                )}
              </div>
              {s.node}
            </section>
          ))}
        </div>
      ) : (
        <EmptyState icon={<SearchIcon size={28} />} title={`Rien ne correspond à « ${q} »`}>
          Vérifie l’orthographe ou essaie un autre mot.
        </EmptyState>
      )}
    </Status>
  );
}

function UsersTab({ q }: { q: string }) {
  const search = useUserSearch(q);
  const users = search.data?.pages.flatMap((p) => p.items) ?? [];
  return (
    <Status query={search}>
      {users.length ? (
        <>
          <p className="sr-only">
            {users.length} résultat{users.length > 1 ? 's' : ''} affiché
            {users.length > 1 ? 's' : ''}
          </p>
          <UserList users={users} label={`Résultats pour « ${q} »`} />
          <More query={search} />
        </>
      ) : (
        <EmptyState icon={<UserIcon size={28} />} title={`Personne ne correspond à « ${q} »`}>
          Vérifie l’orthographe ou essaie un autre nom.
        </EmptyState>
      )}
    </Status>
  );
}

function TracksTab({ q }: { q: string }) {
  const search = useTrackSearch(q);
  const tracks = search.data?.pages.flatMap((p) => p.items) ?? [];
  return (
    <Status query={search}>
      {tracks.length ? (
        <TrackList
          tracks={tracks}
          label={`Morceaux pour « ${q} »`}
          hasMore={!!search.hasNextPage}
          loadingMore={search.isFetchingNextPage}
          onLoadMore={() => void search.fetchNextPage()}
        />
      ) : (
        <Empty q={q} what="Aucun morceau" />
      )}
    </Status>
  );
}

function ArtistsTab({ q }: { q: string }) {
  const search = useSectionSearch('artists', q);
  const items = (search.data?.pages.flatMap((p) => p.items) ?? []) as Artist[];
  return (
    <Status query={search}>
      {items.length ? (
        <>
          <ArtistRows artists={items} label={`Artistes pour « ${q} »`} />
          <More query={search} />
        </>
      ) : (
        <Empty q={q} what="Aucun artiste" />
      )}
    </Status>
  );
}

function PlaylistsTab({ q }: { q: string }) {
  const search = useSectionSearch('playlists', q);
  const items = search.data?.pages.flatMap((p) => p.items) ?? [];
  return (
    <Status query={search}>
      {items.length ? (
        <>
          <PlaylistGrid playlists={items as Playlist[]} label={`Playlists pour « ${q} »`} />
          <More query={search} />
        </>
      ) : (
        <Empty q={q} what="Aucune playlist" />
      )}
    </Status>
  );
}

function HashtagsTab({ q }: { q: string }) {
  const search = useSectionSearch('hashtags', q);
  const items = (search.data?.pages.flatMap((p) => p.items) ?? []) as Hashtag[];
  return (
    <Status query={search}>
      {items.length ? (
        <>
          <HashtagRows hashtags={items} label={`Hashtags pour « ${q} »`} />
          <More query={search} />
        </>
      ) : (
        <Empty q={q} what="Aucun hashtag" />
      )}
    </Status>
  );
}

/** W-43b: tabbed results below the search field (q ≥ 2 chars). */
export function SearchResults({
  q,
  tab,
  onTab,
}: {
  q: string;
  tab: SearchTab;
  onTab: (t: SearchTab) => void;
}) {
  return (
    <div className="mt-4">
      <div role="tablist" aria-label="Type de résultat" className="mb-4 flex gap-2 overflow-x-auto">
        {SEARCH_TABS.map((t) => (
          <button
            key={t.id}
            type="button"
            role="tab"
            id={`search-tab-${t.id}`}
            aria-selected={tab === t.id}
            aria-controls="search-panel"
            onClick={() => onTab(t.id)}
            className={
              'shrink-0 rounded-full px-4 py-1.5 text-sm font-semibold ring-1 transition ' +
              (tab === t.id
                ? 'bg-accent text-accent-contrast ring-accent'
                : 'bg-surface-2 text-muted ring-line hover:text-fg')
            }
          >
            {t.label}
          </button>
        ))}
      </div>
      <div
        role="tabpanel"
        id="search-panel"
        aria-labelledby={`search-tab-${tab}`}
        aria-live="polite"
      >
        {tab === 'all' && <Overview q={q} onTab={onTab} />}
        {tab === 'users' && <UsersTab q={q} />}
        {tab === 'tracks' && <TracksTab q={q} />}
        {tab === 'artists' && <ArtistsTab q={q} />}
        {tab === 'playlists' && <PlaylistsTab q={q} />}
        {tab === 'hashtags' && <HashtagsTab q={q} />}
      </div>
    </div>
  );
}
