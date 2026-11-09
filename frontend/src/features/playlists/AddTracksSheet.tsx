// W-29b (FB-15): "Ajouter des morceaux" sheet of the playlist page (owner only).
import { useState } from 'react';
import { isApiError } from '@/api/errors';
import { useCurrentUser } from '@/api/queries/me';
import { useAddToPlaylist } from '@/api/queries/playlists';
import { useLikedTracks, useTrackSearch } from '@/api/queries/social';
import { useUserTracks } from '@/api/queries/users';
import type { Playlist, PlaylistItem, Track, TrackPage } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { CheckIcon, PlusIcon, SearchIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Cover } from '@/components/ui/Media';
import { Sheet } from '@/components/ui/Sheet';
import { Spinner } from '@/components/ui/Spinner';
import { ErrorState } from '@/components/ui/States';
import { errorMessage, retryHint } from '@/lib/errorMessages';
import { useDebounced } from '@/lib/useDebounced';
import { toast } from '@/stores/toast';
import { SEARCH_DEBOUNCE_MS, SEARCH_MIN_CHARS } from '@/features/search/SearchPage';
import { ADD_ERRORS } from './addErrors';

export function AddTracksSheet({
  playlist,
  items,
  open,
  onClose,
}: {
  playlist: Playlist;
  items: PlaylistItem[] | undefined;
  open: boolean;
  onClose: () => void;
}) {
  return (
    <Sheet open={open} title="Ajouter des morceaux" onClose={onClose}>
      {open && <AddTracks playlist={playlist} items={items} />}
    </Sheet>
  );
}

/** Same rule as the search backend: only ready, non-hidden tracks can be added. */
const addable = (t: Track) => t.status === 'ready' && t.moderation === 'visible';

type InfiniteTracks = {
  data?: { pages: TrackPage[] };
  isPending: boolean;
  isError: boolean;
  error: unknown;
  refetch: () => unknown;
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  fetchNextPage: () => unknown;
};

function AddTracks({ playlist, items }: { playlist: Playlist; items: PlaylistItem[] | undefined }) {
  const me = useCurrentUser();
  const [input, setInput] = useState('');
  const q = useDebounced(input.trim(), SEARCH_DEBOUNCE_MS);
  const tooShort = q.length < SEARCH_MIN_CHARS;
  const search = useTrackSearch(q);
  const own = useUserTracks(me.username, tooShort);
  const liked = useLikedTracks();
  const add = useAddToPlaylist();
  // Added during this sheet session (before the items refetch lands) or reported as duplicate.
  const [added, setAdded] = useState<Set<string>>(() => new Set());
  const inPlaylist = (id: string) => added.has(id) || !!items?.some((i) => i.trackId === id);
  const markAdded = (id: string) => setAdded((s) => new Set(s).add(id));

  const onAdd = (track: Track) =>
    add.mutate(
      { playlist, trackId: track.id },
      {
        onSuccess: () => markAdded(track.id),
        onError: (e) => {
          if (isApiError(e) && e.code === 'track_already_in_playlist') return markAdded(track.id);
          toast((isApiError(e) && ADD_ERRORS[e.code]) || errorMessage(e), 'error');
        },
      },
    );
  const pendingId = add.isPending ? add.variables?.trackId : undefined;

  const list = (label: string, query: InfiniteTracks, empty: string) => (
    <TrackSection
      label={label}
      query={query}
      empty={empty}
      inPlaylist={inPlaylist}
      pendingId={pendingId}
      onAdd={onAdd}
    />
  );

  return (
    <div className="flex flex-col gap-4">
      <form role="search" onSubmit={(e) => e.preventDefault()} className="relative">
        <label htmlFor="add-tracks-input" className="sr-only">
          Rechercher un morceau
        </label>
        <SearchIcon
          size={18}
          className="text-muted pointer-events-none absolute top-1/2 left-4 -translate-y-1/2"
        />
        <input
          id="add-tracks-input"
          data-autofocus
          type="search"
          autoComplete="off"
          enterKeyHint="search"
          maxLength={100}
          placeholder="Titre ou artiste"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          className="bg-surface-2 ring-line placeholder:text-faint focus:ring-accent h-11 w-full rounded-full pr-4 pl-11 ring-1 focus:ring-2 focus:outline-none"
        />
      </form>
      <div aria-live="polite" className="flex flex-col gap-5">
        {tooShort ? (
          <>
            {list('Tes morceaux', own, 'Tu n’as pas encore de morceau prêt.')}
            {list('Tes titres likés', liked, 'Tu n’as pas encore liké de titre.')}
          </>
        ) : isApiError(search.error) && search.error.code === 'rate_limited' ? (
          <Alert tone="warning">
            Tu as fait beaucoup de recherches d’un coup. {retryHint(search.error.retryAfter)}
          </Alert>
        ) : (
          list(`Résultats pour « ${q} »`, search, `Aucun morceau ne correspond à « ${q} ».`)
        )}
      </div>
    </div>
  );
}

function TrackSection({
  label,
  query,
  empty,
  inPlaylist,
  pendingId,
  onAdd,
}: {
  label: string;
  query: InfiniteTracks;
  empty: string;
  inPlaylist: (id: string) => boolean;
  pendingId: string | undefined;
  onAdd: (t: Track) => void;
}) {
  const tracks = query.data?.pages.flatMap((p) => p.items).filter(addable) ?? [];
  return (
    <section aria-label={label}>
      <h3 className="text-muted mb-1 text-xs font-semibold tracking-widest uppercase">{label}</h3>
      {query.isPending ? (
        <div className="text-muted flex justify-center py-4">
          <Spinner label="Chargement…" />
        </div>
      ) : query.isError ? (
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      ) : tracks.length ? (
        <>
          <ul className="flex flex-col">
            {tracks.map((t) => (
              <PickRow
                key={t.id}
                track={t}
                added={inPlaylist(t.id)}
                pending={pendingId === t.id}
                disabled={!!pendingId}
                onAdd={() => onAdd(t)}
              />
            ))}
          </ul>
          <InfiniteSentinel
            hasMore={query.hasNextPage}
            loading={query.isFetchingNextPage}
            onLoadMore={() => void query.fetchNextPage()}
          />
        </>
      ) : (
        <p className="text-muted px-2 py-3 text-sm">{empty}</p>
      )}
    </section>
  );
}

function PickRow({
  track,
  added,
  pending,
  disabled,
  onAdd,
}: {
  track: Track;
  added: boolean;
  pending: boolean;
  disabled: boolean;
  onAdd: () => void;
}) {
  return (
    <li className="flex items-center gap-3 rounded-xl px-1 py-1.5">
      <Cover url={track.coverUrl} seed={track.id} className="size-11" />
      <span className="min-w-0 flex-1">
        <span className="block truncate font-semibold">{track.title}</span>
        <span className="text-muted block truncate text-sm">{track.artist.name}</span>
      </span>
      {added ? (
        <span className="text-muted flex items-center gap-1 px-2 text-sm">
          <CheckIcon size={16} />
          Ajouté
        </span>
      ) : (
        <Button
          variant="secondary"
          size="sm"
          icon={<PlusIcon size={14} />}
          loading={pending}
          disabled={disabled && !pending}
          onClick={onAdd}
          aria-label={`Ajouter ${track.title}`}
        >
          Ajouter
        </Button>
      )}
    </li>
  );
}
