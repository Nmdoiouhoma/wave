import {
  closestCenter,
  DndContext,
  KeyboardSensor,
  PointerSensor,
  TouchSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { useCallback, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { isApiError } from '@/api/errors';
import {
  useDeletePlaylist,
  usePlaylist,
  usePlaylistItems,
  useRemoveFromPlaylist,
  useReorderPlaylist,
  useUpdatePlaylist,
} from '@/api/queries/playlists';
import type { Playlist, PlaylistItem, Track } from '@/api/types';
import { Button, IconButton } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import {
  CloseIcon,
  EditIcon,
  GripIcon,
  ListPlusIcon,
  LockIcon,
  MusicIcon,
  PauseIcon,
  PlayIcon,
  TrashIcon,
} from '@/components/ui/icons';
import { Cover } from '@/components/ui/Media';
import { Sheet } from '@/components/ui/Sheet';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { LikeButton } from '@/features/social/LikeButton';
import { errorMessage } from '@/lib/errorMessages';
import { formatDuration } from '@/lib/format';
import { currentTrack, isPlayable, playerStore, usePlayer } from '@/stores/player';
import { toast } from '@/stores/toast';
import { AddTracksSheet } from './AddTracksSheet';
import { PlaylistForm } from './PlaylistForm';
import { ReportButton } from '@/features/reports/ReportButton';

export const ORDER_CONFLICT_MESSAGE =
  'La playlist a été modifiée ailleurs : l’ordre a été rechargé, recommence ton déplacement.';

export function PlaylistPage() {
  const { id = '' } = useParams();
  const playlist = usePlaylist(id);
  const items = usePlaylistItems(id);

  if (playlist.isPending) return <PageSpinner />;
  if (playlist.isError)
    return <ErrorState error={playlist.error} onRetry={() => void playlist.refetch()} />;
  return <PlaylistView playlist={playlist.data} items={items.data} itemsQuery={items} />;
}

/** Queue = playlist order, unavailable and non-ready tracks skipped. */
function playableTracks(items: PlaylistItem[]): Track[] {
  return items.map((i) => i.track).filter((t): t is Track => !!t && isPlayable(t));
}

function PlaylistView({
  playlist,
  items,
  itemsQuery,
}: {
  playlist: Playlist;
  items: PlaylistItem[] | undefined;
  itemsQuery: ReturnType<typeof usePlaylistItems>;
}) {
  const [editing, setEditing] = useState(false);
  const [adding, setAdding] = useState(false);
  // Stable: Sheet re-runs its focus effect when onClose changes (would steal focus on re-render).
  const closeAdding = useCallback(() => setAdding(false), []);
  const tracks = playableTracks(items ?? []);
  const playingHere = usePlayer((s) => {
    const t = currentTrack(s);
    return (
      !!t && tracks.some((x) => x.id === t.id) && (s.status === 'playing' || s.status === 'loading')
    );
  });
  const owner = playlist.isOwner;

  return (
    <div className="mx-auto max-w-3xl">
      <header className="flex flex-col gap-5 sm:flex-row sm:items-end">
        <Cover
          url={playlist.coverUrl}
          seed={playlist.id}
          rounded="rounded-2xl"
          className="aspect-square w-full max-w-56 self-center shadow-2xl shadow-black/50 sm:self-auto"
        />
        <div className="min-w-0 flex-1">
          <p className="text-muted flex items-center gap-1.5 text-xs font-semibold tracking-widest uppercase">
            {!playlist.isPublic && <LockIcon size={12} />}
            Playlist {playlist.isPublic ? 'publique' : 'privée'}
          </p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight break-words sm:text-4xl">
            {playlist.name}
          </h1>
          {playlist.description && (
            <p className="text-fg/85 mt-2 text-sm">{playlist.description}</p>
          )}
          <p className="text-muted mt-2 text-sm">
            <Link
              to={`/u/${playlist.owner.username}`}
              className="text-fg font-semibold hover:underline"
            >
              {playlist.owner.displayName}
            </Link>{' '}
            · {playlist.itemCount} morceau{playlist.itemCount > 1 ? 'x' : ''}
          </p>
          <div className="mt-4 flex flex-wrap items-center gap-2">
            <Button
              size="lg"
              icon={playingHere ? <PauseIcon size={18} /> : <PlayIcon size={18} />}
              disabled={!tracks.length}
              onClick={() =>
                playingHere
                  ? playerStore.getState().togglePlay()
                  : playerStore.getState().playTracks(tracks)
              }
            >
              {playingHere ? 'Pause' : 'Lecture'}
            </Button>
            {!owner && (
              <LikeButton
                kind="playlist"
                id={playlist.id}
                title={playlist.name}
                server={{ liked: playlist.likedByMe, likeCount: playlist.likeCount }}
                showCount
              />
            )}
            {!owner && (
              <ReportButton target={{ type: 'playlist', id: playlist.id }} className="size-12" />
            )}
            {owner && (
              <Button
                variant="secondary"
                size="lg"
                icon={<ListPlusIcon size={16} />}
                onClick={() => setAdding(true)}
              >
                Ajouter des morceaux
              </Button>
            )}
            {owner && (
              <Button
                variant="secondary"
                size="lg"
                icon={<EditIcon size={16} />}
                onClick={() => setEditing(true)}
              >
                Modifier
              </Button>
            )}
          </div>
        </div>
      </header>

      <section className="mt-8" aria-labelledby="playlist-items">
        <div className="mb-2 flex items-baseline justify-between">
          <h2 id="playlist-items" className="text-lg font-semibold">
            Morceaux
          </h2>
          {owner && items && items.length > 1 && (
            <p className="text-faint text-xs">Fais glisser ⠿ pour réordonner</p>
          )}
        </div>
        {itemsQuery.isPending ? (
          <PageSpinner />
        ) : itemsQuery.isError ? (
          <ErrorState error={itemsQuery.error} onRetry={() => void itemsQuery.refetch()} />
        ) : items && items.length ? (
          owner ? (
            <SortableItems playlist={playlist} items={items} />
          ) : (
            <ul className="flex flex-col">
              {items.map((item) => (
                <ItemRow key={item.trackId} item={item} queue={tracks} />
              ))}
            </ul>
          )
        ) : (
          <EmptyState icon={<MusicIcon size={28} />} title="Cette playlist est vide">
            {owner && 'Utilise « Ajouter des morceaux » ou le menu ⋯ de n’importe quel titre.'}
          </EmptyState>
        )}
      </section>

      {owner && <EditSheet playlist={playlist} open={editing} onClose={() => setEditing(false)} />}
      {owner && (
        <AddTracksSheet playlist={playlist} items={items} open={adding} onClose={closeAdding} />
      )}
    </div>
  );
}

function SortableItems({ playlist, items }: { playlist: Playlist; items: PlaylistItem[] }) {
  const reorder = useReorderPlaylist(playlist.id);
  const remove = useRemoveFromPlaylist(playlist);
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    // Touch: long-press on the handle so vertical scrolling still works.
    useSensor(TouchSensor, { activationConstraint: { delay: 180, tolerance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );
  const queue = playableTracks(items);

  const onDragEnd = ({ active, over }: DragEndEvent) => {
    if (!over || active.id === over.id) return;
    const from = items.findIndex((i) => i.trackId === active.id);
    const to = items.findIndex((i) => i.trackId === over.id);
    reorder.mutate(arrayMove(items, from, to), {
      onError: (e) =>
        toast(
          isApiError(e) && e.code === 'playlist_order_mismatch'
            ? ORDER_CONFLICT_MESSAGE
            : errorMessage(e),
          'error',
        ),
    });
  };

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragEnd={onDragEnd}
      accessibility={{
        screenReaderInstructions: {
          draggable:
            'Pour déplacer un morceau, appuie sur Espace ou Entrée, utilise les flèches, puis Espace pour déposer ou Échap pour annuler.',
        },
        announcements: {
          onDragStart: ({ active }) => `Morceau ${title(items, active.id)} saisi.`,
          onDragOver: ({ active, over }) =>
            over
              ? `${title(items, active.id)} : position ${index(items, over.id) + 1} sur ${items.length}.`
              : '',
          onDragEnd: ({ active, over }) =>
            over
              ? `${title(items, active.id)} déposé en position ${index(items, over.id) + 1}.`
              : 'Déplacement annulé.',
          onDragCancel: () => 'Déplacement annulé.',
        },
      }}
    >
      <SortableContext items={items.map((i) => i.trackId)} strategy={verticalListSortingStrategy}>
        <ul className="flex flex-col">
          {items.map((item) => (
            <SortableRow
              key={item.trackId}
              item={item}
              queue={queue}
              onRemove={() =>
                remove.mutate(item.trackId, { onError: (e) => toast(errorMessage(e), 'error') })
              }
            />
          ))}
        </ul>
      </SortableContext>
    </DndContext>
  );
}

const title = (items: PlaylistItem[], id: unknown) =>
  items.find((i) => i.trackId === id)?.track?.title ?? 'indisponible';
const index = (items: PlaylistItem[], id: unknown) => items.findIndex((i) => i.trackId === id);

function SortableRow({
  item,
  queue,
  onRemove,
}: {
  item: PlaylistItem;
  queue: Track[];
  onRemove: () => void;
}) {
  const {
    attributes,
    listeners,
    setNodeRef,
    setActivatorNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: item.trackId });
  const name = item.track?.title ?? 'Titre indisponible';
  return (
    <ItemRow
      item={item}
      queue={queue}
      rowRef={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      dragging={isDragging}
      handle={
        <button
          type="button"
          ref={setActivatorNodeRef}
          {...attributes}
          {...listeners}
          aria-label={`Déplacer ${name}`}
          className="text-faint hover:text-fg flex size-10 shrink-0 cursor-grab touch-none items-center justify-center rounded-full active:cursor-grabbing"
        >
          <GripIcon size={18} />
        </button>
      }
      trailing={
        <IconButton label={`Retirer ${name} de la playlist`} onClick={onRemove} className="size-9">
          <CloseIcon size={16} />
        </IconButton>
      }
    />
  );
}

function ItemRow({
  item,
  queue,
  handle,
  trailing,
  rowRef,
  style,
  dragging,
}: {
  item: PlaylistItem;
  queue: Track[];
  handle?: React.ReactNode;
  trailing?: React.ReactNode;
  rowRef?: (el: HTMLElement | null) => void;
  style?: React.CSSProperties;
  dragging?: boolean;
}) {
  const t = item.track;
  const current = usePlayer((s) => currentTrack(s)?.id === item.trackId);
  const playable = !!t && isPlayable(t);
  return (
    <li
      ref={rowRef}
      style={style}
      className={cx(
        'bg-bg flex items-center gap-2 rounded-xl px-1 py-1.5',
        dragging && 'bg-surface-2 ring-accent/50 relative z-10 shadow-xl ring-1',
      )}
    >
      {handle}
      <span className="text-faint w-6 text-right text-xs tabular-nums">{item.position + 1}</span>
      <button
        type="button"
        disabled={!playable}
        onClick={() => t && playerStore.getState().playTracks(queue, Math.max(0, queue.indexOf(t)))}
        aria-label={t ? `Lire ${t.title}` : 'Titre indisponible'}
        className="disabled:cursor-not-allowed"
      >
        <Cover
          url={t?.coverUrl ?? null}
          seed={item.trackId}
          className={cx('size-11', !playable && 'opacity-40')}
        />
      </button>
      <div className="min-w-0 flex-1">
        {t ? (
          <>
            <Link
              to={`/t/${t.id}`}
              className={cx(
                'block truncate font-semibold hover:underline',
                current && 'text-accent-strong',
              )}
            >
              {t.title}
            </Link>
            <Link
              to={`/a/${t.artist.slug}`}
              className="text-muted block truncate text-sm hover:underline"
            >
              {t.artist.name}
            </Link>
          </>
        ) : (
          <>
            <p className="text-muted truncate font-semibold">Titre indisponible</p>
            <p className="text-faint truncate text-xs">Supprimé ou rendu privé par son auteur</p>
          </>
        )}
      </div>
      {t && (
        <LikeButton
          kind="track"
          id={t.id}
          title={t.title}
          server={{ liked: t.likedByMe, likeCount: t.likeCount }}
          className="hidden sm:inline-flex"
        />
      )}
      <span className="text-muted hidden w-12 text-right text-sm tabular-nums sm:block">
        {formatDuration(t?.durationMs)}
      </span>
      {trailing}
    </li>
  );
}

function EditSheet({
  playlist,
  open,
  onClose,
}: {
  playlist: Playlist;
  open: boolean;
  onClose: () => void;
}) {
  const update = useUpdatePlaylist(playlist.id);
  const del = useDeletePlaylist();
  const navigate = useNavigate();
  const [confirm, setConfirm] = useState(false);
  return (
    <Sheet open={open} title="Modifier la playlist" onClose={onClose}>
      <PlaylistForm
        key={playlist.updatedAt}
        initial={playlist}
        submitLabel="Enregistrer"
        pending={update.isPending}
        error={update.error}
        onSubmit={(body) =>
          update.mutate(body, {
            onSuccess: () => {
              onClose();
              toast('Playlist mise à jour', 'success');
            },
          })
        }
      />
      <div className="border-line mt-6 flex flex-wrap items-center gap-2 border-t pt-4">
        {confirm ? (
          <>
            <span className="text-sm">Supprimer « {playlist.name} » ?</span>
            <Button
              variant="danger"
              size="sm"
              loading={del.isPending}
              onClick={() =>
                del.mutate(playlist, {
                  onSuccess: () => {
                    toast('Playlist supprimée', 'success');
                    void navigate('/library', { replace: true });
                  },
                  onError: (e) => toast(errorMessage(e), 'error'),
                })
              }
            >
              Confirmer
            </Button>
            <Button variant="ghost" size="sm" onClick={() => setConfirm(false)}>
              Annuler
            </Button>
          </>
        ) : (
          <Button
            variant="danger"
            size="sm"
            icon={<TrashIcon size={16} />}
            onClick={() => setConfirm(true)}
          >
            Supprimer la playlist
          </Button>
        )}
      </div>
    </Sheet>
  );
}
