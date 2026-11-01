// "⋯" actions of a track and the "Ajouter à une playlist" sheet, mounted once in the layout.
import { useState } from 'react';
import { useNavigate } from 'react-router';
import { isApiError } from '@/api/errors';
import { useCurrentUser } from '@/api/queries/me';
import { useAddToPlaylist, useCreatePlaylist, useUserPlaylists } from '@/api/queries/playlists';
import type { Playlist, Track } from '@/api/types';
import { Button } from '@/components/ui/Button';
import {
  ListPlusIcon,
  LockIcon,
  MicIcon,
  MusicIcon,
  PlusIcon,
  QueueIcon,
} from '@/components/ui/icons';
import { Cover } from '@/components/ui/Media';
import { Sheet } from '@/components/ui/Sheet';
import { Spinner } from '@/components/ui/Spinner';
import { errorMessage } from '@/lib/errorMessages';
import { playerStore } from '@/stores/player';
import { toast } from '@/stores/toast';
import { uiStore, useUi } from '@/stores/ui';
import { PlaylistForm } from './PlaylistForm';

export function TrackSheets() {
  const actions = useUi((s) => s.trackActions);
  const adding = useUi((s) => s.addToPlaylist);
  const close = uiStore.getState().closeSheets;
  return (
    <>
      <Sheet open={!!actions} title={actions?.title ?? ''} onClose={close}>
        {actions && <TrackActions track={actions} />}
      </Sheet>
      <Sheet open={!!adding} title="Ajouter à une playlist" onClose={close}>
        {adding && <AddToPlaylist track={adding} />}
      </Sheet>
    </>
  );
}

function ActionButton({
  icon,
  label,
  onClick,
  disabled,
}: {
  icon: React.ReactNode;
  label: string;
  onClick: () => void;
  disabled?: boolean;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      disabled={disabled}
      className="hover:bg-surface-2 flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left font-medium disabled:opacity-40"
    >
      <span className="text-muted">{icon}</span>
      {label}
    </button>
  );
}

function TrackActions({ track }: { track: Track }) {
  const navigate = useNavigate();
  const ready = track.status === 'ready';
  const go = (to: string) => {
    uiStore.getState().closeSheets();
    void navigate(to);
  };
  return (
    <div className="flex flex-col">
      <ActionButton
        icon={<ListPlusIcon size={20} />}
        label="Ajouter à une playlist"
        disabled={!ready}
        onClick={() => uiStore.getState().openAddToPlaylist(track)}
      />
      <ActionButton
        icon={<QueueIcon size={20} />}
        label="Ajouter à la file d’attente"
        disabled={!ready}
        onClick={() => {
          playerStore.getState().enqueue(track);
          uiStore.getState().closeSheets();
          toast('Ajouté à la file d’attente', 'success');
        }}
      />
      <ActionButton
        icon={<MusicIcon size={20} />}
        label="Voir le morceau"
        onClick={() => go(`/t/${track.id}`)}
      />
      <ActionButton
        icon={<MicIcon size={20} />}
        label={`Aller à ${track.artist.name}`}
        onClick={() => go(`/a/${track.artist.slug}`)}
      />
    </div>
  );
}

const ADD_ERRORS: Record<string, string> = {
  track_already_in_playlist: 'Ce morceau est déjà dans cette playlist.',
  playlist_full: 'Cette playlist est pleine (500 morceaux maximum).',
  track_not_ready: 'Ce morceau n’est pas encore prêt.',
};

function AddToPlaylist({ track }: { track: Track }) {
  const me = useCurrentUser();
  const playlists = useUserPlaylists(me.username);
  const add = useAddToPlaylist();
  const create = useCreatePlaylist();
  const [creating, setCreating] = useState(false);
  const mine = playlists.data?.pages.flatMap((p) => p.items).filter((p) => p.isOwner) ?? [];

  const addTo = (playlist: Playlist) =>
    add.mutate(
      { playlist, trackId: track.id },
      {
        onSuccess: () => {
          uiStore.getState().closeSheets();
          toast(`Ajouté à « ${playlist.name} »`, 'success');
        },
        onError: (e) => toast((isApiError(e) && ADD_ERRORS[e.code]) || errorMessage(e), 'error'),
      },
    );

  if (creating) {
    return (
      <PlaylistForm
        submitLabel="Créer et ajouter"
        pending={create.isPending || add.isPending}
        error={create.error}
        onSubmit={(body) => create.mutate(body, { onSuccess: addTo })}
      />
    );
  }

  return (
    <div className="flex flex-col gap-2">
      <p className="text-muted -mt-2 mb-1 truncate text-sm">« {track.title} »</p>
      <Button variant="secondary" icon={<PlusIcon size={16} />} onClick={() => setCreating(true)}>
        Nouvelle playlist
      </Button>
      {playlists.isPending ? (
        <div className="text-muted flex justify-center py-4">
          <Spinner />
        </div>
      ) : (
        <ul aria-label="Tes playlists" className="flex flex-col">
          {mine.map((p) => (
            <li key={p.id}>
              <button
                type="button"
                onClick={() => addTo(p)}
                disabled={add.isPending}
                className="hover:bg-surface-2 flex w-full items-center gap-3 rounded-xl px-2 py-2 text-left disabled:opacity-60"
              >
                <Cover url={p.coverUrl} seed={p.id} className="size-11" />
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5 font-semibold">
                    <span className="truncate">{p.name}</span>
                    {!p.isPublic && <LockIcon size={13} className="text-muted shrink-0" />}
                  </span>
                  <span className="text-muted text-xs">
                    {p.itemCount} morceau{p.itemCount > 1 ? 'x' : ''}
                    {!p.isPublic && ' · privée'}
                  </span>
                </span>
              </button>
            </li>
          ))}
          {!mine.length && (
            <li className="text-muted px-2 py-3 text-sm">
              Tu n’as pas encore de playlist : crée la première.
            </li>
          )}
        </ul>
      )}
    </div>
  );
}
