import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router';
import { useGenres } from '@/api/queries/genres';
import {
  useDeleteTrack,
  useDeleteTrackCover,
  useTrack,
  useUpdateTrack,
  useUploadTrackCover,
} from '@/api/queries/tracks';
import { useUserArtists } from '@/api/queries/users';
import type { Track, UpdateTrackRequest, Visibility } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { SelectField, TextField } from '@/components/ui/Field';
import { AllowClipsField } from '@/features/tracks/AllowClipsField';
import { EditIcon, PauseIcon, PlayIcon, TrashIcon } from '@/components/ui/icons';
import { ImageUploader } from '@/components/ui/ImageUploader';
import { Avatar, Cover } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { ErrorState } from '@/components/ui/States';
import { formErrors } from '@/features/auth/formErrors';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { errorMessage, failureMessage } from '@/lib/errorMessages';
import { formatCount, formatDate, formatDuration } from '@/lib/format';
import { currentTrack, playerStore, usePlayer } from '@/stores/player';
import { TrackStatusBadge } from './TrackStatusBadge';

export function TrackPage() {
  const { id = '' } = useParams();
  const query = useTrack(id);
  if (query.isPending) return <PageSpinner />;
  if (query.isError) return <ErrorState error={query.error} onRetry={() => void query.refetch()} />;
  return <TrackView track={query.data} refetch={query.refetch} />;
}

function TrackView({ track, refetch }: { track: Track; refetch: () => unknown }) {
  const [editing, setEditing] = useState(false);
  const isCurrent = usePlayer((s) => currentTrack(s)?.id === track.id);
  const status = usePlayer((s) => s.status);
  const playing = isCurrent && (status === 'playing' || status === 'loading');

  return (
    <article className="mx-auto max-w-3xl">
      <header className="flex flex-col gap-5 sm:flex-row sm:items-end">
        <Cover
          url={track.coverUrl}
          seed={track.id}
          rounded="rounded-2xl"
          className="aspect-square w-full max-w-60 self-center shadow-2xl shadow-black/50 sm:self-auto"
        />
        <div className="min-w-0 flex-1">
          <p className="text-muted text-xs font-semibold tracking-widest uppercase">Titre</p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight break-words sm:text-4xl">
            {track.title}
          </h1>
          <div className="text-muted mt-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            <Link
              to={`/a/${track.artist.slug}`}
              className="text-fg flex items-center gap-2 font-semibold hover:underline"
            >
              <Avatar
                url={track.artist.avatarUrl}
                name={track.artist.name}
                seed={track.artist.id}
                className="size-6 text-[10px]"
              />
              {track.artist.name}
            </Link>
            <span aria-hidden>·</span>
            <span>{track.genre.name}</span>
            <span aria-hidden>·</span>
            <span>{formatDuration(track.durationMs)}</span>
            {track.status === 'ready' && (
              <>
                <span aria-hidden>·</span>
                <span>{formatCount(track.playCount)} écoutes</span>
              </>
            )}
            <TrackStatusBadge track={track} />
          </div>
          <p className="text-faint mt-1 text-xs">
            Publié par{' '}
            <Link to={`/u/${track.owner.username}`} className="hover:underline">
              @{track.owner.username}
            </Link>{' '}
            le {formatDate(track.createdAt)}
          </p>
          <div className="mt-5 flex flex-wrap gap-2">
            <Button
              size="lg"
              icon={playing ? <PauseIcon size={18} /> : <PlayIcon size={18} />}
              disabled={track.status !== 'ready'}
              onClick={() =>
                isCurrent
                  ? playerStore.getState().togglePlay()
                  : playerStore.getState().playTracks([track])
              }
            >
              {playing ? 'Pause' : 'Écouter'}
            </Button>
            {track.isOwner && (
              <Button
                size="lg"
                variant="secondary"
                icon={<EditIcon size={16} />}
                onClick={() => setEditing((v) => !v)}
                aria-expanded={editing}
              >
                Modifier
              </Button>
            )}
          </div>
        </div>
      </header>

      {track.status === 'processing' && (
        <Alert tone="info" className="mt-6">
          Traitement de l’audio en cours, cette page se met à jour automatiquement.
        </Alert>
      )}
      {track.status === 'failed' && (
        <Alert tone="error" className="mt-6">
          {failureMessage(track.failureReason)}
        </Alert>
      )}

      {track.isOwner && editing && <OwnerPanel track={track} refetch={refetch} />}
    </article>
  );
}

function OwnerPanel({ track, refetch }: { track: Track; refetch: () => unknown }) {
  const genres = useGenres();
  const artists = useUserArtists(track.owner.username);
  const update = useUpdateTrack(track.id);
  const uploadCover = useUploadTrackCover(track.id);
  const deleteCover = useDeleteTrackCover(track.id);
  const del = useDeleteTrack();
  const navigate = useNavigate();
  const coverGate = useVerifiedGate('changer la pochette');
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [form, setForm] = useState({
    title: track.title,
    genreId: track.genre.id,
    artistId: track.artist.id,
    visibility: track.visibility as Visibility,
    allowClips: track.allowClips,
  });
  const { fields, message } = formErrors(update.error, [
    'title',
    'genreId',
    'artistId',
    'visibility',
    'allowClips',
  ]);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    const patch: UpdateTrackRequest = {};
    if (form.title.trim() !== track.title) patch.title = form.title.trim();
    if (form.genreId !== track.genre.id) patch.genreId = form.genreId;
    if (form.artistId !== track.artist.id) patch.artistId = form.artistId;
    if (form.visibility !== track.visibility) patch.visibility = form.visibility;
    if (form.allowClips !== track.allowClips) patch.allowClips = form.allowClips;
    if (Object.keys(patch).length) update.mutate(patch);
  };

  return (
    <section aria-label="Gestion du titre" className="mt-8 grid gap-6 md:grid-cols-[1fr_16rem]">
      <form
        onSubmit={onSubmit}
        className="bg-surface ring-line flex flex-col gap-4 rounded-2xl p-5 ring-1"
        noValidate
      >
        <h2 className="font-semibold">Informations</h2>
        {message && <Alert tone="error">{message}</Alert>}
        {update.isSuccess && <Alert tone="success">Modifications enregistrées.</Alert>}
        <TextField
          label="Titre"
          value={form.title}
          maxLength={120}
          onChange={(e) => setForm({ ...form, title: e.target.value })}
          error={fields.title}
        />
        <SelectField
          label="Genre"
          value={form.genreId}
          onChange={(e) => setForm({ ...form, genreId: e.target.value })}
          error={fields.genreId}
        >
          {(genres.data ?? [track.genre]).map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Artiste"
          value={form.artistId}
          onChange={(e) => setForm({ ...form, artistId: e.target.value })}
          error={fields.artistId}
        >
          {(artists.data ?? [track.artist]).map((a) => (
            <option key={a.id} value={a.id}>
              {a.name}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Visibilité"
          value={form.visibility}
          onChange={(e) => setForm({ ...form, visibility: e.target.value as Visibility })}
          error={fields.visibility}
        >
          <option value="public">Public</option>
          <option value="private">Privé</option>
        </SelectField>
        <AllowClipsField
          visibility={form.visibility}
          checked={form.allowClips}
          onChange={(v) => setForm({ ...form, allowClips: v })}
        />
        <Button type="submit" loading={update.isPending}>
          Enregistrer
        </Button>
      </form>

      <div className="flex flex-col gap-6">
        <div className="bg-surface ring-line rounded-2xl p-5 ring-1">
          <h2 className="mb-3 font-semibold">Pochette</h2>
          <ImageUploader
            currentUrl={track.coverUrl}
            preview={<Cover url={track.coverUrl} seed={track.id} className="size-20" />}
            upload={(f) => uploadCover.mutateAsync(f)}
            remove={() => deleteCover.mutateAsync()}
            refetch={refetch}
            disabledReason={coverGate}
            label="Changer"
          />
        </div>
        <div className="bg-danger/5 ring-danger/20 rounded-2xl p-5 ring-1">
          <h2 className="text-danger font-semibold">Supprimer le titre</h2>
          <p className="text-muted mt-1 text-xs">
            Définitif : les posts qui l’utilisent seront aussi supprimés.
          </p>
          {del.isError && <p className="text-danger mt-2 text-xs">{errorMessage(del.error)}</p>}
          {confirmDelete ? (
            <div className="mt-3 flex gap-2">
              <Button
                variant="danger"
                size="sm"
                loading={del.isPending}
                onClick={() =>
                  del.mutate(track, {
                    onSuccess: () => {
                      const s = playerStore.getState();
                      const i = s.queue.findIndex((t) => t.id === track.id);
                      if (i >= 0) s.removeAt(i);
                      void navigate(`/u/${track.owner.username}`, { replace: true });
                    },
                  })
                }
              >
                Confirmer
              </Button>
              <Button variant="ghost" size="sm" onClick={() => setConfirmDelete(false)}>
                Annuler
              </Button>
            </div>
          ) : (
            <Button
              variant="danger"
              size="sm"
              className="mt-3"
              icon={<TrashIcon size={16} />}
              onClick={() => setConfirmDelete(true)}
            >
              Supprimer
            </Button>
          )}
        </div>
      </div>
    </section>
  );
}
