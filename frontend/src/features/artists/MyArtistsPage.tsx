import { useState, type FormEvent } from 'react';
import { Link, useSearchParams } from 'react-router';
import {
  useArtist,
  useCreateArtist,
  useDeleteArtist,
  useDeleteArtistAvatar,
  useUpdateArtist,
  useUploadArtistAvatar,
} from '@/api/queries/artists';
import { useCurrentUser } from '@/api/queries/me';
import { useUserArtists } from '@/api/queries/users';
import type { Artist } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextArea, TextField } from '@/components/ui/Field';
import { EditIcon, MicIcon, PlusIcon, TrashIcon } from '@/components/ui/icons';
import { ImageUploader } from '@/components/ui/ImageUploader';
import { Avatar } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { formErrors } from '@/features/auth/formErrors';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { errorMessage } from '@/lib/errorMessages';

const MAX_ARTISTS = 10;

export function MyArtistsPage() {
  const me = useCurrentUser();
  const artists = useUserArtists(me.username);
  const gate = useVerifiedGate('créer ou modifier un artiste');
  const [params, setParams] = useSearchParams();
  const editing = params.get('edit');
  const [creating, setCreating] = useState(false);
  const setEditing = (slug: string | null) =>
    setParams(slug ? { edit: slug } : {}, { replace: true });

  if (artists.isPending) return <PageSpinner />;
  if (artists.isError)
    return <ErrorState error={artists.error} onRetry={() => void artists.refetch()} />;
  const list = artists.data;
  const full = list.length >= MAX_ARTISTS;

  return (
    <div className="mx-auto max-w-2xl">
      <div className="flex items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Mes artistes</h1>
          <p className="text-muted mt-1 text-sm">
            {list.length}/{MAX_ARTISTS} · chaque titre est publié sous l’un de vos artistes.
          </p>
        </div>
        {!creating && (
          <Button
            icon={<PlusIcon size={16} />}
            onClick={() => setCreating(true)}
            disabled={!!gate || full}
          >
            Nouvel artiste
          </Button>
        )}
      </div>
      {gate && (
        <Alert tone="warning" className="mt-4">
          {gate}
        </Alert>
      )}
      {full && (
        <p className="text-faint mt-2 text-xs">
          Vous avez atteint la limite de {MAX_ARTISTS} artistes.
        </p>
      )}

      {creating && <CreateArtistForm onDone={() => setCreating(false)} />}

      <ul className="mt-6 flex flex-col gap-3">
        {list.map((a) => (
          <li key={a.id} className="bg-surface ring-line rounded-2xl ring-1">
            <div className="flex items-center gap-4 p-4">
              <Avatar url={a.avatarUrl} name={a.name} seed={a.id} className="size-14 text-lg" />
              <div className="min-w-0 flex-1">
                <Link to={`/a/${a.slug}`} className="block truncate font-semibold hover:underline">
                  {a.name}
                </Link>
                <p className="text-muted text-xs">
                  {a.trackCount} titre{a.trackCount > 1 ? 's' : ''} public
                  {a.trackCount > 1 ? 's' : ''} · /a/{a.slug}
                </p>
              </div>
              <Button
                variant="ghost"
                size="sm"
                icon={<EditIcon size={16} />}
                aria-expanded={editing === a.slug}
                onClick={() => setEditing(editing === a.slug ? null : a.slug)}
              >
                {editing === a.slug ? 'Fermer' : 'Modifier'}
              </Button>
            </div>
            {editing === a.slug && (
              <ArtistEditor artist={a} gate={gate} onDeleted={() => setEditing(null)} />
            )}
          </li>
        ))}
      </ul>
      {!list.length && !creating && (
        <div className="mt-6">
          <EmptyState icon={<MicIcon size={28} />} title="Aucun artiste">
            Créez-en un, ou importez directement un titre : un artiste à votre nom sera créé.
          </EmptyState>
        </div>
      )}
    </div>
  );
}

function CreateArtistForm({ onDone }: { onDone: () => void }) {
  const [name, setName] = useState('');
  const [bio, setBio] = useState('');
  const create = useCreateArtist();
  const { fields, message } = formErrors(create.error, ['name', 'bio']);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    create.mutate({ name: name.trim(), bio: bio.trim() || null }, { onSuccess: onDone });
  };

  return (
    <form
      onSubmit={onSubmit}
      className="bg-surface ring-accent/40 mt-6 flex flex-col gap-4 rounded-2xl p-5 ring-1"
      noValidate
    >
      <h2 className="font-semibold">Nouvel artiste</h2>
      {message && <Alert tone="error">{message}</Alert>}
      <TextField
        label="Nom"
        value={name}
        maxLength={80}
        onChange={(e) => setName(e.target.value)}
        error={fields.name}
        hint="L’adresse de la page est générée à partir du nom et ne change plus ensuite."
      />
      <TextArea
        label="Bio (facultatif)"
        value={bio}
        maxLength={1000}
        onChange={(e) => setBio(e.target.value)}
        error={fields.bio}
      />
      <div className="flex gap-2">
        <Button type="submit" loading={create.isPending} disabled={!name.trim()}>
          Créer
        </Button>
        <Button variant="ghost" onClick={onDone}>
          Annuler
        </Button>
      </div>
    </form>
  );
}

function ArtistEditor({
  artist,
  gate,
  onDeleted,
}: {
  artist: Artist;
  gate: string | null;
  onDeleted: () => void;
}) {
  const [name, setName] = useState(artist.name);
  const [bio, setBio] = useState(artist.bio ?? '');
  const [confirm, setConfirm] = useState(false);
  const update = useUpdateArtist(artist.slug);
  const del = useDeleteArtist();
  const uploadAvatar = useUploadArtistAvatar(artist.slug);
  const deleteAvatar = useDeleteArtistAvatar(artist.slug);
  // Live artist query, polled by the uploader while the avatar is processed.
  const live = useArtist(artist.slug);
  const current = live.data ?? artist;
  const { fields, message } = formErrors(update.error, ['name', 'bio']);

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    update.mutate({ name: name.trim(), bio: bio.trim() || null });
  };

  return (
    <div className="border-line flex flex-col gap-6 border-t p-4">
      <ImageUploader
        currentUrl={current.avatarUrl}
        preview={
          <Avatar
            url={current.avatarUrl}
            name={current.name}
            seed={current.id}
            className="size-20 text-2xl"
          />
        }
        upload={(f) => uploadAvatar.mutateAsync(f)}
        remove={() => deleteAvatar.mutateAsync()}
        refetch={live.refetch}
        disabledReason={gate}
        label="Changer l’avatar"
      />
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        {message && <Alert tone="error">{message}</Alert>}
        {update.isSuccess && <Alert tone="success">Artiste mis à jour.</Alert>}
        <TextField
          label="Nom"
          value={name}
          maxLength={80}
          onChange={(e) => setName(e.target.value)}
          error={fields.name}
          disabled={!!gate}
          hint={`Le lien reste /a/${artist.slug}.`}
        />
        <TextArea
          label="Bio"
          value={bio}
          maxLength={1000}
          onChange={(e) => setBio(e.target.value)}
          error={fields.bio}
          disabled={!!gate}
        />
        <div>
          <Button type="submit" loading={update.isPending} disabled={!!gate || !name.trim()}>
            Enregistrer
          </Button>
        </div>
      </form>
      <div className="border-line flex flex-wrap items-center gap-2 border-t pt-4">
        {confirm ? (
          <>
            <span className="text-sm">Supprimer {artist.name} ?</span>
            <Button
              variant="danger"
              size="sm"
              loading={del.isPending}
              onClick={() => del.mutate(artist, { onSuccess: onDeleted })}
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
            Supprimer l’artiste
          </Button>
        )}
        {del.isError && (
          <p role="alert" className="text-danger w-full text-sm">
            {errorMessage(del.error)}
          </p>
        )}
      </div>
    </div>
  );
}
