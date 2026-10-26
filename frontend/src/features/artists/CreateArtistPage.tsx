// FB-05: artist creation in two obvious steps — 1. the artist, 2. its tracks (optional).
import { useCallback, useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useCreateArtist } from '@/api/queries/artists';
import type { Artist } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { TextArea, TextField } from '@/components/ui/Field';
import { CheckIcon } from '@/components/ui/icons';
import { formErrors } from '@/features/auth/formErrors';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { MultiUploader } from '@/features/tracks/MultiUploader';

function Steps({ step }: { step: 1 | 2 }) {
  const items = ['Ton artiste', 'Ses morceaux'] as const;
  return (
    <ol className="flex items-center gap-2 text-sm" aria-label="Étapes">
      {items.map((label, i) => {
        const n = (i + 1) as 1 | 2;
        const done = n < step;
        const current = n === step;
        return (
          <li
            key={label}
            className="flex items-center gap-2"
            aria-current={current ? 'step' : undefined}
          >
            {i > 0 && <span aria-hidden className="bg-line h-px w-6" />}
            <span
              className={cx(
                'flex size-7 items-center justify-center rounded-full text-xs font-bold',
                done
                  ? 'bg-success/20 text-success'
                  : current
                    ? 'bg-wave text-white'
                    : 'bg-surface-2 text-faint',
              )}
            >
              {done ? <CheckIcon size={14} /> : n}
            </span>
            <span className={current ? 'font-semibold' : 'text-muted'}>
              <span className="sr-only">Étape {n} : </span>
              {label}
            </span>
          </li>
        );
      })}
    </ol>
  );
}

export function CreateArtistPage() {
  const [artist, setArtist] = useState<Artist | null>(null);
  return (
    <div className="mx-auto flex max-w-xl flex-col gap-6">
      <div className="flex flex-col gap-3">
        <Link to="/me/artists" className="text-muted hover:text-fg text-sm">
          ← Mes artistes
        </Link>
        <Steps step={artist ? 2 : 1} />
      </div>
      {artist ? <TracksStep artist={artist} /> : <ArtistStep onCreated={setArtist} />}
    </div>
  );
}

function ArtistStep({ onCreated }: { onCreated: (a: Artist) => void }) {
  const [name, setName] = useState('');
  const [bio, setBio] = useState('');
  const create = useCreateArtist();
  const gate = useVerifiedGate('créer un artiste');
  const { fields, message } = formErrors(create.error, ['name', 'bio']);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate({ name: name.trim(), bio: bio.trim() || null }, { onSuccess: onCreated });
  };

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Crée ton artiste</h1>
        <p className="text-muted mt-1 text-sm">
          Le nom sous lequel tes morceaux seront publiés. Tu ajoutes les morceaux juste après.
        </p>
      </div>
      {gate && <Alert tone="warning">{gate}</Alert>}
      {message && <Alert tone="error">{message}</Alert>}
      <TextField
        label="Nom de l’artiste"
        value={name}
        maxLength={80}
        onChange={(e) => setName(e.target.value)}
        error={fields.name}
        disabled={!!gate}
        hint="L’adresse de la page est générée à partir du nom et ne change plus ensuite."
      />
      <TextArea
        label="Bio (facultatif)"
        value={bio}
        maxLength={1000}
        onChange={(e) => setBio(e.target.value)}
        error={fields.bio}
        disabled={!!gate}
      />
      <Button type="submit" size="lg" loading={create.isPending} disabled={!!gate || !name.trim()}>
        Continuer : ajouter des morceaux
      </Button>
    </form>
  );
}

function TracksStep({ artist }: { artist: Artist }) {
  const [summary, setSummary] = useState({ total: 0, running: 0, uploaded: 0 });
  const onProgressChange = useCallback(
    (s: { total: number; running: number; uploaded: number }) => setSummary(s),
    [],
  );
  const busy = summary.running > 0;
  return (
    <div className="flex flex-col gap-5">
      <div>
        <h1 className="text-2xl font-bold tracking-tight">Ajoute tes morceaux</h1>
        <p className="text-muted mt-1 text-sm">
          <strong className="text-fg">{artist.name}</strong> est créé. Dépose un ou plusieurs
          fichiers : ils seront publiés sous cet artiste. Tu peux aussi le faire plus tard.
        </p>
      </div>
      <MultiUploader artist={artist} onProgressChange={onProgressChange} />
      <div className="border-line flex flex-wrap items-center justify-between gap-3 border-t pt-4">
        {busy && <p className="text-muted text-xs">Envoi en cours : reste sur cette page.</p>}
        <div className="ml-auto flex gap-2">
          {summary.uploaded === 0 && !busy && (
            <ButtonLink to={`/a/${artist.slug}`} variant="ghost">
              Passer cette étape
            </ButtonLink>
          )}
          {summary.uploaded > 0 && (
            <ButtonLink to={`/a/${artist.slug}`} variant={busy ? 'secondary' : 'primary'}>
              Voir la page de {artist.name}
            </ButtonLink>
          )}
        </div>
      </div>
    </div>
  );
}
