import { useState, type FormEvent } from 'react';
import type { CreatePlaylistRequest, Playlist } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextArea, TextField, Toggle } from '@/components/ui/Field';
import { formErrors } from '@/features/auth/formErrors';

export function PlaylistForm({
  initial,
  submitLabel,
  pending,
  error,
  onSubmit,
}: {
  initial?: Playlist;
  submitLabel: string;
  pending: boolean;
  error: unknown;
  onSubmit: (body: CreatePlaylistRequest) => void;
}) {
  const [name, setName] = useState(initial?.name ?? '');
  const [description, setDescription] = useState(initial?.description ?? '');
  const [isPublic, setIsPublic] = useState(initial?.isPublic ?? true);
  const { fields, message } = formErrors(error, ['name', 'description', 'isPublic']);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    onSubmit({ name: name.trim(), description: description.trim() || null, isPublic });
  };

  return (
    <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
      {message && <Alert tone="error">{message}</Alert>}
      <TextField
        label="Nom de la playlist"
        value={name}
        maxLength={100}
        onChange={(e) => setName(e.target.value)}
        error={fields.name}
        data-autofocus
      />
      <TextArea
        label="Description (facultatif)"
        value={description}
        maxLength={300}
        onChange={(e) => setDescription(e.target.value)}
        error={fields.description}
      />
      <Toggle
        label="Playlist publique"
        description={
          isPublic
            ? 'Visible sur ton profil, tout le monde peut l’écouter.'
            : 'Privée : visible par toi seul·e.'
        }
        checked={isPublic}
        onChange={setIsPublic}
      />
      <Button type="submit" loading={pending} disabled={!name.trim()}>
        {submitLabel}
      </Button>
    </form>
  );
}
