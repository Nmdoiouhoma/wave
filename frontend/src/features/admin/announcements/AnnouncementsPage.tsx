import { useInfiniteQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { isApiError } from '@/api/errors';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { SelectField, TextArea, TextField } from '@/components/ui/Field';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Sheet } from '@/components/ui/Sheet';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/States';
import { errorMessage } from '@/lib/errorMessages';
import { toast } from '@/stores/toast';
import { AdminError } from '../AdminError';
import { Badge } from '../moderation/parts';
import {
  announcementsApi,
  type Announcement,
  type AnnouncementLevel,
} from './announcementsApi';

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'short' });
const pad = (n: number) => String(n).padStart(2, '0');
/** ISO instant → value of a `datetime-local` input (local time). */
function toLocal(iso: string | null): string {
  if (!iso) return '';
  const d = new Date(iso);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
const toIso = (local: string) => new Date(local).toISOString();

function status(a: Announcement, now = Date.now()): { label: string; tone: 'success' | 'neutral' | 'warning' } {
  if (new Date(a.startsAt).getTime() > now) return { label: 'Programmée', tone: 'warning' };
  if (a.endsAt && new Date(a.endsAt).getTime() <= now) return { label: 'Terminée', tone: 'neutral' };
  return { label: 'Active', tone: 'success' };
}

function formError(e: unknown): string {
  if (isApiError(e) && e.status === 422)
    return 'Données invalides : la fin doit suivre le début, titre 80 et texte 500 caractères maximum.';
  if (isApiError(e) && e.status === 404) return 'Cette annonce n’existe plus.';
  return errorMessage(e);
}

function AnnouncementForm({
  initial,
  pending,
  error,
  onSubmit,
  onCancel,
}: {
  initial: Announcement | null;
  pending: boolean;
  error: string | null;
  onSubmit: (v: {
    title: string;
    body: string;
    level: AnnouncementLevel;
    startsAt: string | null;
    endsAt: string | null;
  }) => void;
  onCancel: () => void;
}) {
  const [title, setTitle] = useState(initial?.title ?? '');
  const [body, setBody] = useState(initial?.body ?? '');
  const [level, setLevel] = useState<AnnouncementLevel>(initial?.level ?? 'info');
  const [startsAt, setStartsAt] = useState(toLocal(initial?.startsAt ?? null));
  const [endsAt, setEndsAt] = useState(toLocal(initial?.endsAt ?? null));
  const badRange = !!startsAt && !!endsAt && new Date(endsAt) <= new Date(startsAt);
  const valid = title.trim() !== '' && body.trim() !== '' && !badRange;
  const submit = (e: FormEvent) => {
    e.preventDefault();
    if (!valid) return;
    onSubmit({
      title: title.trim(),
      body: body.trim(),
      level,
      startsAt: startsAt ? toIso(startsAt) : null,
      endsAt: endsAt ? toIso(endsAt) : null,
    });
  };
  return (
    <form onSubmit={submit} className="flex flex-col gap-4">
      <TextField
        label="Titre"
        value={title}
        maxLength={80}
        onChange={(e) => setTitle(e.target.value)}
        data-autofocus
      />
      <TextArea
        label="Texte"
        value={body}
        maxLength={500}
        onChange={(e) => setBody(e.target.value)}
        hint={`${body.length}/500 · texte brut, sans mise en forme`}
      />
      <SelectField
        label="Niveau"
        value={level}
        onChange={(e) => setLevel(e.target.value as AnnouncementLevel)}
      >
        <option value="info">Information</option>
        <option value="warning">Avertissement</option>
      </SelectField>
      <TextField
        label="Début"
        type="datetime-local"
        value={startsAt}
        onChange={(e) => setStartsAt(e.target.value)}
        hint={initial ? undefined : 'Vide : tout de suite'}
      />
      <TextField
        label="Fin"
        type="datetime-local"
        value={endsAt}
        onChange={(e) => setEndsAt(e.target.value)}
        error={badRange ? 'La fin doit suivre le début' : null}
        hint="Vide : sans fin"
      />
      {error && <Alert tone="error">{error}</Alert>}
      <div className="flex justify-end gap-2">
        <Button variant="ghost" onClick={onCancel}>
          Annuler
        </Button>
        <Button type="submit" loading={pending} disabled={!valid}>
          {initial ? 'Enregistrer' : 'Publier'}
        </Button>
      </div>
    </form>
  );
}

export function AnnouncementsPage() {
  const [editing, setEditing] = useState<Announcement | 'new' | null>(null);
  const [deleting, setDeleting] = useState<Announcement | null>(null);
  const qc = useQueryClient();
  const q = useInfiniteQuery({
    queryKey: ['admin', 'announcements'],
    queryFn: ({ pageParam }) => announcementsApi.list(pageParam),
    initialPageParam: null as string | null,
    getNextPageParam: (l) => l.nextCursor,
  });
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['admin', 'announcements'] });
    void qc.invalidateQueries({ queryKey: ['announcements'] });
  };
  const save = useMutation({
    mutationFn: (v: Parameters<typeof announcementsApi.create>[0]) =>
      editing && editing !== 'new'
        ? announcementsApi.update(editing.id, {
            ...v,
            startsAt: v.startsAt ?? undefined,
          })
        : announcementsApi.create(v),
    onSuccess: () => {
      toast(editing === 'new' ? 'Annonce publiée.' : 'Annonce mise à jour.', 'success');
      setEditing(null);
      refresh();
    },
  });
  const del = useMutation({
    mutationFn: (id: string) => announcementsApi.remove(id),
    onSuccess: () => {
      toast('Annonce supprimée.', 'success');
      setDeleting(null);
      refresh();
    },
  });
  const items = q.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-2xl font-semibold">Annonces</h1>
        <Button
          onClick={() => {
            save.reset();
            setEditing('new');
          }}
        >
          Nouvelle annonce
        </Button>
      </div>
      {q.isPending ? (
        <div className="flex justify-center py-10">
          <Spinner label="Chargement des annonces" />
        </div>
      ) : q.isError ? (
        <AdminError error={q.error} onRetry={() => void q.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState title="Aucune annonce">Crée la première avec le bouton ci-dessus.</EmptyState>
      ) : (
        <ul className="flex flex-col gap-3">
          {items.map((a) => {
            const st = status(a);
            return (
              <li
                key={a.id}
                className="bg-surface border-line flex flex-col gap-2 rounded-2xl border p-4"
              >
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone={st.tone}>{st.label}</Badge>
                  <Badge tone={a.level === 'warning' ? 'warning' : 'neutral'}>
                    {a.level === 'warning' ? 'Avertissement' : 'Information'}
                  </Badge>
                  <span className="font-medium break-words">{a.title}</span>
                </div>
                <p className="text-sm break-words whitespace-pre-line">{a.body}</p>
                <p className="text-muted text-xs">
                  Du {dateTime.format(new Date(a.startsAt))}{' '}
                  {a.endsAt ? `au ${dateTime.format(new Date(a.endsAt))}` : 'sans fin'}
                </p>
                <div className="flex gap-2">
                  <Button
                    variant="secondary"
                    onClick={() => {
                      save.reset();
                      setEditing(a);
                    }}
                  >
                    Modifier
                  </Button>
                  <Button
                    variant="danger"
                    onClick={() => {
                      del.reset();
                      setDeleting(a);
                    }}
                  >
                    Supprimer
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      )}
      <InfiniteSentinel
        hasMore={!!q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
      <Sheet
        open={!!editing}
        title={editing === 'new' ? 'Nouvelle annonce' : 'Modifier l’annonce'}
        onClose={() => setEditing(null)}
      >
        {editing && (
          <AnnouncementForm
            initial={editing === 'new' ? null : editing}
            pending={save.isPending}
            error={save.isError ? formError(save.error) : null}
            onSubmit={(v) => save.mutate(v)}
            onCancel={() => setEditing(null)}
          />
        )}
      </Sheet>
      <Sheet open={!!deleting} title="Supprimer l’annonce" onClose={() => setDeleting(null)}>
        {deleting && (
          <div className="flex flex-col gap-4">
            <p className="text-muted text-sm">
              « {deleting.title} » disparaît définitivement. Pour l’arrêter seulement, modifie sa
              date de fin.
            </p>
            {del.isError && <Alert tone="error">{formError(del.error)}</Alert>}
            <div className="flex justify-end gap-2">
              <Button variant="ghost" onClick={() => setDeleting(null)}>
                Annuler
              </Button>
              <Button variant="danger" loading={del.isPending} onClick={() => del.mutate(deleting.id)}>
                Supprimer
              </Button>
            </div>
          </div>
        )}
      </Sheet>
    </section>
  );
}
