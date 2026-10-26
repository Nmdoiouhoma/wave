// One uploader for every entry point (Importer page, artist creation step 2, artist page):
// drop several files, adjust title/genre per file, send (max 3 in parallel), follow each one.
import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { useGenres } from '@/api/queries/genres';
import { TRACK_POLL_TIMEOUT_MS, useInvalidateAfterUpload, useTrack } from '@/api/queries/tracks';
import { useUserArtists } from '@/api/queries/users';
import type { Genre, Track, Visibility } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button, IconButton } from '@/components/ui/Button';
import { SelectField, Toggle } from '@/components/ui/Field';
import { cx } from '@/components/ui/cx';
import { CheckIcon, CloseIcon, MusicIcon, PlayIcon, UploadIcon } from '@/components/ui/icons';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { failureMessage } from '@/lib/errorMessages';
import { TRACK_ACCEPT } from '@/lib/files';
import { formatBytes, formatDuration } from '@/lib/format';
import { playerStore } from '@/stores/player';
import { isActive, useUploadQueue, type UploadItem } from './useUploadQueue';

export interface MultiUploaderProps {
  /** Fixed artist (artist page, creation wizard): no artist picker. */
  artist?: { id: string; name: string };
  /** Preselected artist in the picker (Importer page `?artist=`). */
  initialArtistId?: string;
  /** Called whenever the set of uploads changes state (e.g. to show a "done" button). */
  onProgressChange?: (summary: { total: number; running: number; uploaded: number }) => void;
}

export function MultiUploader({ artist, initialArtistId, onProgressChange }: MultiUploaderProps) {
  const me = useCurrentUser();
  const gate = useVerifiedGate('importer des morceaux');
  const genres = useGenres();
  const artists = useUserArtists(artist ? undefined : me.username);
  const invalidate = useInvalidateAfterUpload();
  const queue = useUploadQueue({ onUploaded: invalidate });
  const { items } = queue;

  const ownedArtists = artists.data ?? [];
  const [artistChoice, setArtistChoice] = useState<string | null>(initialArtistId ?? null);
  const [visibility, setVisibility] = useState<Visibility>('public');
  const [allowClips, setAllowClips] = useState(true);
  const [genreForAll, setGenreForAll] = useState('');
  const [dragOver, setDragOver] = useState(false);
  const input = useRef<HTMLInputElement>(null);

  const artistId =
    artist?.id ??
    (artistChoice && ownedArtists.some((a) => a.id === artistChoice)
      ? artistChoice
      : ownedArtists.length === 1
        ? ownedArtists[0]!.id
        : '');
  const needsArtistChoice = !artist && ownedArtists.length > 1 && !artistId;

  const ready = items.filter((i) => i.status === 'ready');
  const running = items.filter(isActive).length;
  const uploaded = items.filter((i) => i.status === 'uploaded').length;
  const incomplete = ready.filter((i) => !i.title.trim() || !i.genreId);

  useEffect(() => {
    onProgressChange?.({ total: items.length, running, uploaded });
  }, [items.length, running, uploaded, onProgressChange]);

  const addFiles = (files: FileList | File[] | null | undefined) => {
    if (!files || gate) return;
    queue.addFiles(files, genreForAll);
  };

  const applyGenreToAll = (genreId: string) => {
    setGenreForAll(genreId);
    queue.setItems((prev) => prev.map((i) => (i.status === 'ready' ? { ...i, genreId } : i)));
  };

  const send = () => queue.start({ artistId: artistId || undefined, visibility, allowClips });

  return (
    <div className="flex flex-col gap-5">
      {gate && <Alert tone="warning">{gate}</Alert>}

      <div>
        <input
          ref={input}
          id="track-files"
          type="file"
          multiple
          accept={TRACK_ACCEPT}
          className="sr-only"
          disabled={!!gate}
          onChange={(e) => {
            addFiles(e.target.files);
            e.target.value = '';
          }}
        />
        <label
          htmlFor="track-files"
          onDragOver={(e) => {
            e.preventDefault();
            setDragOver(true);
          }}
          onDragLeave={() => setDragOver(false)}
          onDrop={(e) => {
            e.preventDefault();
            setDragOver(false);
            addFiles(e.dataTransfer.files);
          }}
          className={cx(
            'flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed px-4 py-8 text-center transition',
            dragOver
              ? 'border-accent bg-accent/10'
              : 'border-line hover:border-accent/50 hover:bg-surface',
            gate && 'pointer-events-none opacity-60',
          )}
        >
          <span className="bg-wave flex size-12 items-center justify-center rounded-full text-white">
            <UploadIcon size={22} />
          </span>
          <span className="font-semibold">
            {items.length ? 'Ajouter d’autres fichiers' : 'Choisis tes morceaux'}
          </span>
          <span className="text-muted text-xs">
            ou glisse-les ici · plusieurs fichiers à la fois
          </span>
          <span className="text-faint text-xs">
            MP3, AAC/M4A jusqu’à 30 Mo · MP4 jusqu’à 200 Mo (seul le son est gardé) · 10 min max
          </span>
        </label>
      </div>

      {items.length > 0 && (
        <>
          <section
            aria-label="Réglages communs"
            className="bg-surface ring-line flex flex-col gap-4 rounded-2xl p-4 ring-1"
          >
            {artist ? (
              <p className="text-sm">
                Publié sous <strong>{artist.name}</strong>
              </p>
            ) : ownedArtists.length === 0 ? (
              <p className="text-muted text-sm">
                {artists.isLoading ? (
                  'Chargement de tes artistes…'
                ) : (
                  <>
                    Ces morceaux seront publiés sous un nouvel artiste nommé{' '}
                    <strong className="text-fg">{me.displayName}</strong>.{' '}
                    <Link to="/me/artists/new" className="text-accent-strong hover:underline">
                      Créer un autre artiste
                    </Link>
                  </>
                )}
              </p>
            ) : (
              <SelectField
                label="Artiste"
                value={artistId}
                onChange={(e) => setArtistChoice(e.target.value)}
                error={needsArtistChoice ? 'Choisis sous quel artiste publier ces morceaux.' : null}
              >
                {!artistId && (
                  <option value="" disabled>
                    Choisir l’artiste
                  </option>
                )}
                {ownedArtists.map((a) => (
                  <option key={a.id} value={a.id}>
                    {a.name}
                  </option>
                ))}
              </SelectField>
            )}
            <SelectField
              label="Genre pour tous les fichiers"
              value={genreForAll}
              onChange={(e) => applyGenreToAll(e.target.value)}
              hint="Tu peux ensuite le changer fichier par fichier."
            >
              <option value="">Choisir un genre</option>
              {genres.data?.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name}
                </option>
              ))}
            </SelectField>
            <div className="grid grid-cols-2 gap-2" role="radiogroup" aria-label="Visibilité">
              {(
                [
                  ['public', 'Public', 'Visible par tout le monde'],
                  ['private', 'Privé', 'Visible par toi seul·e'],
                ] as const
              ).map(([value, label, desc]) => (
                <label
                  key={value}
                  className={cx(
                    'has-[:focus-visible]:ring-accent flex cursor-pointer flex-col rounded-xl px-4 py-3 ring-1 transition has-[:focus-visible]:ring-2',
                    visibility === value ? 'bg-accent/10 ring-accent' : 'bg-surface-2 ring-line',
                  )}
                >
                  <input
                    type="radio"
                    name="visibility"
                    value={value}
                    checked={visibility === value}
                    onChange={() => setVisibility(value)}
                    className="sr-only"
                  />
                  <span className="text-sm font-semibold">{label}</span>
                  <span className="text-muted text-xs">{desc}</span>
                </label>
              ))}
            </div>
            <Toggle
              label="Autoriser les extraits"
              description="D’autres membres pourront utiliser ces titres dans leurs posts."
              checked={allowClips}
              onChange={setAllowClips}
            />
          </section>

          <ul aria-label="Fichiers à importer" className="flex flex-col gap-2">
            {items.map((item) => (
              <UploadRow
                key={item.id}
                item={item}
                genres={genres.data ?? []}
                onTitle={(title) => queue.update(item.id, { title })}
                onGenre={(genreId) => queue.update(item.id, { genreId })}
                onRemove={() => queue.remove(item.id)}
                onCancel={() => queue.cancel(item.id)}
                onRetry={() => queue.retry(item.id)}
              />
            ))}
          </ul>

          {ready.length > 0 && (
            <div className="flex flex-col gap-2">
              {incomplete.length > 0 && (
                <p className="text-warning text-xs">
                  Ajoute un titre et un genre à chaque fichier avant l’envoi.
                </p>
              )}
              <Button
                size="lg"
                onClick={send}
                disabled={!!gate || incomplete.length > 0 || needsArtistChoice}
                icon={<UploadIcon size={18} />}
              >
                {ready.length === 1 ? 'Envoyer le morceau' : `Envoyer les ${ready.length} morceaux`}
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}

const PHASE_LABEL: Partial<Record<UploadItem['status'], string>> = {
  queued: 'En attente…',
  preparing: 'Préparation…',
  creating: 'Création du titre…',
  cancelled: 'Annulé',
};

function UploadRow({
  item,
  genres,
  onTitle,
  onGenre,
  onRemove,
  onCancel,
  onRetry,
}: {
  item: UploadItem;
  genres: Genre[];
  onTitle: (v: string) => void;
  onGenre: (v: string) => void;
  onRemove: () => void;
  onCancel: () => void;
  onRetry: () => void;
}) {
  const editable = item.status === 'ready';
  const busy = isActive(item);
  return (
    <li
      className={cx(
        'bg-surface flex flex-col gap-3 rounded-2xl p-3 ring-1',
        item.status === 'invalid' || item.status === 'error' ? 'ring-danger/40' : 'ring-line',
      )}
    >
      <div className="flex items-center gap-3">
        <span className="bg-surface-2 text-muted flex size-10 shrink-0 items-center justify-center rounded-lg">
          <MusicIcon size={18} />
        </span>
        <div className="min-w-0 flex-1">
          {editable ? (
            <input
              aria-label={`Titre de ${item.file.name}`}
              value={item.title}
              maxLength={120}
              onChange={(e) => onTitle(e.target.value)}
              className="bg-surface-2 ring-line focus:ring-accent h-9 w-full rounded-lg px-3 text-sm font-semibold ring-1 focus:ring-2 focus:outline-none"
            />
          ) : (
            <p className="truncate text-sm font-semibold">{item.title || item.file.name}</p>
          )}
          <p className="text-faint mt-0.5 truncate text-xs">
            {item.file.name} · {formatBytes(item.file.size)}
          </p>
        </div>
        {busy ? (
          <IconButton label={`Annuler l’envoi de ${item.file.name}`} onClick={onCancel}>
            <CloseIcon size={16} />
          </IconButton>
        ) : (
          item.status !== 'uploaded' && (
            <IconButton label={`Retirer ${item.file.name}`} onClick={onRemove}>
              <CloseIcon size={16} />
            </IconButton>
          )
        )}
      </div>

      {editable && (
        <select
          aria-label={`Genre de ${item.file.name}`}
          value={item.genreId}
          onChange={(e) => onGenre(e.target.value)}
          className={cx(
            'bg-surface-2 ring-line h-9 rounded-lg px-3 text-sm ring-1',
            !item.genreId && 'text-muted',
          )}
        >
          <option value="">Genre…</option>
          {genres.map((g) => (
            <option key={g.id} value={g.id}>
              {g.name}
            </option>
          ))}
        </select>
      )}

      <div aria-live="polite">
        {item.status === 'invalid' && <p className="text-danger text-xs">{item.error}</p>}
        {item.status === 'sending' && (
          <div className="flex items-center gap-3">
            <ProgressBar value={item.progress} label={`Envoi de ${item.file.name}`} />
            <span className="text-muted w-12 text-right text-xs tabular-nums">
              {Math.round(item.progress * 100)} %
            </span>
          </div>
        )}
        {PHASE_LABEL[item.status] && (
          <p className="text-muted text-xs">{PHASE_LABEL[item.status]}</p>
        )}
        {item.status === 'error' && (
          <div className="flex items-center justify-between gap-2">
            <p className="text-danger text-xs">{item.error}</p>
            <Button variant="secondary" size="sm" onClick={onRetry}>
              Réessayer
            </Button>
          </div>
        )}
        {item.status === 'uploaded' && item.track && <ProcessingLine track={item.track} />}
      </div>
    </li>
  );
}

/** After the 202: follows processing (poll 3 s, up to 10 min). */
function ProcessingLine({ track: initial }: { track: Track }) {
  const { data: track = initial } = useTrack(initial.id, { initialData: initial });
  const [timedOut, setTimedOut] = useState(false);
  useEffect(() => {
    const id = setTimeout(() => setTimedOut(true), TRACK_POLL_TIMEOUT_MS);
    return () => clearTimeout(id);
  }, []);

  if (track.status === 'ready') {
    return (
      <div className="flex items-center justify-between gap-2">
        <p className="text-success flex items-center gap-1.5 text-xs font-semibold">
          <CheckIcon size={14} /> Prêt · {formatDuration(track.durationMs)}
        </p>
        <div className="flex gap-1">
          <Button
            variant="ghost"
            size="sm"
            icon={<PlayIcon size={14} />}
            onClick={() => playerStore.getState().playTracks([track])}
          >
            Écouter
          </Button>
          <Link to={`/t/${track.id}`} className="text-muted hover:text-fg self-center px-2 text-xs">
            Voir
          </Link>
        </div>
      </div>
    );
  }
  if (track.status === 'failed') {
    return <p className="text-danger text-xs">Échec : {failureMessage(track.failureReason)}</p>;
  }
  return (
    <div className="flex flex-col gap-1.5">
      <ProgressBar value={null} label={`Traitement de ${track.title}`} />
      <p className="text-muted text-xs">
        {timedOut
          ? 'Le traitement prend plus de temps que prévu : le titre apparaîtra sur ta page dès qu’il sera prêt.'
          : 'Fichier reçu, traitement de l’audio en cours…'}
      </p>
    </div>
  );
}
