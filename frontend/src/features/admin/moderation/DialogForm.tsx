import { useState, type FormEvent, type ReactNode } from 'react';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextArea, TextField } from '@/components/ui/Field';
import { NOTE_MAX } from './helpers';

export function DialogForm({
  description,
  confirm,
  danger,
  noteLabel,
  noteRequired,
  typed,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  description: ReactNode;
  confirm: string;
  danger: boolean;
  noteLabel: string;
  noteRequired: boolean;
  typed?: string;
  pending: boolean;
  error: string | null;
  onSubmit: (note: string) => void;
  onCancel: () => void;
}) {
  const [note, setNote] = useState('');
  const [word, setWord] = useState('');
  const tooLong = note.length > NOTE_MAX;
  const missing = noteRequired && note.trim() === '';
  const wordOk = !typed || word === typed;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!tooLong && !missing && wordOk) onSubmit(note.trim());
  };
  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <p className="text-muted text-sm">{description}</p>
      <TextArea
        label={noteLabel}
        value={note}
        onChange={(e) => setNote(e.target.value)}
        error={tooLong ? `${NOTE_MAX} caractères maximum` : null}
        hint={`${note.length}/${NOTE_MAX} · inscrite au journal d’audit`}
        data-autofocus
      />
      {typed && (
        <TextField
          label={`Tape ${typed} pour confirmer`}
          value={word}
          onChange={(e) => setWord(e.target.value)}
          autoComplete="off"
        />
      )}
      {error && <Alert tone="error">{error}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
        <Button
          type="submit"
          variant={danger ? 'danger' : 'primary'}
          loading={pending}
          disabled={tooLong || missing || !wordOk}
        >
          {confirm}
        </Button>
      </div>
    </form>
  );
}
