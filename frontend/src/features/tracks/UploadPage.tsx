import { useEffect, useRef, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { api } from '@/api/instance';
import { isApiError } from '@/api/errors';
import { useCurrentUser } from '@/api/queries/me';
import { useGenres } from '@/api/queries/genres';
import { TRACK_POLL_TIMEOUT_MS, useInvalidateAfterUpload, useTrack } from '@/api/queries/tracks';
import { useUserArtists } from '@/api/queries/users';
import type { Track, Visibility } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import { SelectField, TextField, Toggle } from '@/components/ui/Field';
import { cx } from '@/components/ui/cx';
import { CheckIcon, MusicIcon, PlayIcon, UploadIcon } from '@/components/ui/icons';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { Spinner } from '@/components/ui/Spinner';
import { formErrors } from '@/features/auth/formErrors';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { checkTrackFile, titleFromFilename, TRACK_ACCEPT } from '@/lib/files';
import { failureMessage } from '@/lib/errorMessages';
import { formatBytes, formatDuration } from '@/lib/format';
import { playerStore } from '@/stores/player';

const FIELDS = ['file', 'title', 'genreId', 'artistId', 'visibility', 'allowClips'] as const;

export function UploadPage() {
  const [uploaded, setUploaded] = useState<Track | null>(null);
  return (
    <div className="mx-auto max-w-xl">
      <h1 className="text-2xl font-bold tracking-tight">Importer un titre</h1>
      <p className="text-muted mt-1 text-sm">MP3 ou AAC/M4A, 30 Mo et 10 minutes maximum.</p>
      <div className="mt-6">
        {uploaded ? (
          <ProcessingStatus
            key={uploaded.id}
            initial={uploaded}
            onAnother={() => setUploaded(null)}
          />
        ) : (
          <UploadForm onUploaded={setUploaded} />
        )}
      </div>
    </div>
  );
}

function UploadForm({ onUploaded }: { onUploaded: (t: Track) => void }) {
  const me = useCurrentUser();
  const gate = useVerifiedGate('importer des titres');
  const genres = useGenres();
  const artists = useUserArtists(me.username);
  const invalidate = useInvalidateAfterUpload();
  const ownedArtists = artists.data ?? [];

  const fileInput = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [fileError, setFileError] = useState<string | null>(null);
  const [title, setTitle] = useState('');
  const [genreId, setGenreId] = useState('');
  const [artistChoice, setArtistChoice] = useState<string | null>(null);
  const [visibility, setVisibility] = useState<Visibility>('public');
  const [allowClips, setAllowClips] = useState(true);
  const [progress, setProgress] = useState<number | null>(null);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState<unknown>(null);
  const abort = useRef<AbortController | null>(null);

  // A single owned artist is preselected; several require an explicit choice (else 422 artist_required).
  const artistId = artistChoice ?? (ownedArtists.length === 1 ? ownedArtists[0]!.id : '');
  const { fields, message } = formErrors(error, FIELDS, { artist_required: 'artistId' });

  const pick = (f: File | undefined) => {
    if (!f) return;
    const problem = checkTrackFile(f);
    setFileError(problem);
    setFile(problem ? null : f);
    if (!problem && !title) setTitle(titleFromFilename(f.name));
  };

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!file) {
      setFileError('Choisissez un fichier audio.');
      return;
    }
    if (!title.trim() || !genreId) return;
    setError(null);
    setUploading(true);
    setProgress(0);
    abort.current = new AbortController();
    try {
      const track = await api.tracks.upload(
        file,
        { title: title.trim(), genreId, artistId: artistId || undefined, visibility, allowClips },
        {
          onProgress: (loaded, total) => setProgress(total ? loaded / total : null),
          signal: abort.current.signal,
        },
      );
      invalidate(track);
      onUploaded(track);
    } catch (err) {
      if (!(isApiError(err) && err.code === 'aborted')) setError(err);
    } finally {
      setUploading(false);
      setProgress(null);
    }
  };

  return (
    <form onSubmit={(e) => void submit(e)} className="flex flex-col gap-5" noValidate>
      {gate && <Alert tone="warning">{gate}</Alert>}
      {message && <Alert tone="error">{message}</Alert>}

      <div>
        <input
          ref={fileInput}
          id="track-file"
          type="file"
          accept={TRACK_ACCEPT}
          className="sr-only"
          onChange={(e) => pick(e.target.files?.[0])}
          disabled={uploading || !!gate}
          aria-describedby="track-file-help"
        />
        <label
          htmlFor="track-file"
          onDragOver={(e) => e.preventDefault()}
          onDrop={(e) => {
            e.preventDefault();
            if (!uploading && !gate) pick(e.dataTransfer.files[0]);
          }}
          className={cx(
            'flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed px-4 py-8 text-center transition',
            file
              ? 'border-accent/60 bg-accent/5'
              : 'border-line hover:border-accent/50 hover:bg-surface',
            (uploading || gate) && 'pointer-events-none opacity-60',
          )}
        >
          <span className="bg-wave flex size-12 items-center justify-center rounded-full text-white">
            {file ? <MusicIcon size={22} /> : <UploadIcon size={22} />}
          </span>
          {file ? (
            <>
              <span className="max-w-full truncate font-semibold">{file.name}</span>
              <span className="text-muted text-xs">
                {formatBytes(file.size)} · toucher pour changer
              </span>
            </>
          ) : (
            <>
              <span className="font-semibold">Choisir un fichier audio</span>
              <span id="track-file-help" className="text-muted text-xs">
                ou glissez-déposez-le ici
              </span>
            </>
          )}
        </label>
        {(fileError || fields.file) && (
          <p className="text-danger mt-1.5 text-xs font-medium">{fileError ?? fields.file}</p>
        )}
      </div>

      <TextField
        label="Titre"
        value={title}
        maxLength={120}
        onChange={(e) => setTitle(e.target.value)}
        error={fields.title ?? (error && !title.trim() ? 'Le titre est requis.' : null)}
        required
        disabled={uploading}
      />

      <SelectField
        label="Genre"
        value={genreId}
        onChange={(e) => setGenreId(e.target.value)}
        error={fields.genreId}
        required
        disabled={uploading || genres.isLoading}
      >
        <option value="" disabled>
          {genres.isLoading ? 'Chargement…' : 'Choisir un genre'}
        </option>
        {genres.data?.map((g) => (
          <option key={g.id} value={g.id}>
            {g.name}
          </option>
        ))}
      </SelectField>

      {ownedArtists.length === 0 ? (
        <p className="bg-surface-2 text-muted ring-line rounded-xl px-4 py-3 text-sm ring-1">
          {artists.isLoading ? (
            'Chargement de vos artistes…'
          ) : (
            <>
              Le titre sera publié sous un nouvel artiste nommé{' '}
              <strong className="text-fg">{me.displayName}</strong>.{' '}
              <Link to="/me/artists" className="text-accent-strong hover:underline">
                Gérer mes artistes
              </Link>
            </>
          )}
        </p>
      ) : (
        <SelectField
          label="Artiste"
          value={artistId}
          onChange={(e) => setArtistChoice(e.target.value)}
          error={fields.artistId}
          disabled={uploading}
        >
          {ownedArtists.length > 1 && (
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

      <fieldset className="flex flex-col gap-2">
        <legend className="mb-1.5 text-sm font-medium">Visibilité</legend>
        <div className="grid grid-cols-2 gap-2">
          {(
            [
              ['public', 'Public', 'Visible par tout le monde'],
              ['private', 'Privé', 'Visible par vous seul·e'],
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
                disabled={uploading}
              />
              <span className="text-sm font-semibold">{label}</span>
              <span className="text-muted text-xs">{desc}</span>
            </label>
          ))}
        </div>
      </fieldset>

      <Toggle
        label="Autoriser les extraits"
        description="D’autres membres pourront utiliser ce titre dans leurs posts."
        checked={allowClips}
        onChange={setAllowClips}
        disabled={uploading}
      />

      {uploading && (
        <div className="flex flex-col gap-2" aria-live="polite">
          <div className="flex justify-between text-sm">
            <span>Envoi en cours…</span>
            <span className="text-muted tabular-nums">
              {progress === null ? '' : `${Math.round(progress * 100)} %`}
            </span>
          </div>
          <ProgressBar value={progress} label="Progression de l’envoi" />
        </div>
      )}

      <div className="flex gap-3">
        <Button
          type="submit"
          size="lg"
          className="flex-1"
          loading={uploading}
          disabled={
            !!gate || !file || !title.trim() || !genreId || (ownedArtists.length > 1 && !artistId)
          }
        >
          Importer
        </Button>
        {uploading && (
          <Button variant="secondary" size="lg" onClick={() => abort.current?.abort()}>
            Annuler
          </Button>
        )}
      </div>
    </form>
  );
}

function ProcessingStatus({ initial, onAnother }: { initial: Track; onAnother: () => void }) {
  const { data: track = initial } = useTrack(initial.id, { initialData: initial });
  const [timedOut, setTimedOut] = useState(false);

  useEffect(() => {
    const id = setTimeout(() => setTimedOut(true), TRACK_POLL_TIMEOUT_MS);
    return () => clearTimeout(id);
  }, []);

  return (
    <div
      className="bg-surface ring-line flex flex-col gap-5 rounded-2xl p-5 ring-1"
      aria-live="polite"
    >
      <div className="flex items-center gap-3">
        <span
          className={cx(
            'flex size-11 items-center justify-center rounded-full',
            track.status === 'ready'
              ? 'bg-success/15 text-success'
              : track.status === 'failed'
                ? 'bg-danger/15 text-danger'
                : 'bg-accent/15 text-accent-strong',
          )}
        >
          {track.status === 'ready' ? <CheckIcon /> : track.status === 'failed' ? '!' : <Spinner />}
        </span>
        <div className="min-w-0">
          <p className="truncate font-semibold">{track.title}</p>
          <p className="text-muted truncate text-sm">{track.artist.name}</p>
        </div>
      </div>

      {track.status === 'processing' && !timedOut && (
        <div className="flex flex-col gap-2">
          <p className="text-sm">
            Fichier reçu. Nous vérifions et préparons l’audio (normalisation du volume)…
          </p>
          <ProgressBar value={null} label="Traitement en cours" />
        </div>
      )}
      {track.status === 'processing' && timedOut && (
        <Alert tone="warning">
          Le traitement prend plus de temps que prévu. Le titre apparaîtra dans votre profil dès
          qu’il sera prêt.
        </Alert>
      )}
      {track.status === 'ready' && (
        <Alert tone="success">Votre titre est prêt ({formatDuration(track.durationMs)}).</Alert>
      )}
      {track.status === 'failed' && (
        <Alert tone="error">{failureMessage(track.failureReason)}</Alert>
      )}

      <div className="flex flex-wrap gap-2">
        {track.status === 'ready' && (
          <Button
            icon={<PlayIcon size={16} />}
            onClick={() => playerStore.getState().playTracks([track])}
          >
            Écouter
          </Button>
        )}
        <ButtonLink to={`/t/${track.id}`} variant="secondary">
          Voir le titre
        </ButtonLink>
        <Button variant="ghost" onClick={onAnother}>
          Importer un autre titre
        </Button>
      </div>
    </div>
  );
}
