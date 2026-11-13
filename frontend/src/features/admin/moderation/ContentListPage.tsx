import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router';
import { Button } from '@/components/ui/Button';
import { SelectField, TextField } from '@/components/ui/Field';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Cover } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { useSession } from '@/stores/session';
import { useDecisionDialog, useDeleteDialog } from './ModerationDialogs';
import type { AdminContentType, Moderation } from './moderationApi';
import {
  CONTENT_LABEL,
  CONTENT_TYPES,
  MEDIA_STATUS_LABEL,
  TARGET_LABEL,
  VISIBILITY_LABEL,
} from './moderationLabels';
import { reportTargetPath, when } from './helpers';
import { Badge, Card, ModerationBadge } from './parts';
import { useAdminContent } from './useModeration';

function useDebounced<T>(value: T, ms = 300): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export function ContentListPage() {
  const [params, setParams] = useSearchParams();
  const isAdmin = useSession((s) => s.user?.roles.includes('ROLE_ADMIN') ?? false);
  const rawType = params.get('type') as AdminContentType;
  const type: AdminContentType = CONTENT_TYPES.includes(rawType) ? rawType : 'track';
  const moderation = (params.get('moderation') as Moderation | null) ?? undefined;
  const ownerId = params.get('ownerId') ?? undefined;
  const [search, setSearch] = useState(params.get('q') ?? '');
  const debounced = useDebounced(search.trim());
  const q = debounced.length >= 2 ? debounced : undefined;

  const set = (k: string, v: string | undefined) =>
    setParams(
      (p) => {
        const n = new URLSearchParams(p);
        if (v) n.set(k, v);
        else n.delete(k);
        return n;
      },
      { replace: true },
    );

  const list = useAdminContent({ type, moderation, ownerId, q });
  const decision = useDecisionDialog();
  const del = useDeleteDialog();
  const items = list.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold">Contenus</h1>
      <div className="grid gap-3 sm:grid-cols-3">
        <SelectField label="Type" value={type} onChange={(e) => set('type', e.target.value)}>
          {CONTENT_TYPES.map((t) => (
            <option key={t} value={t}>
              {CONTENT_LABEL[t]}
            </option>
          ))}
        </SelectField>
        <SelectField
          label="Modération"
          value={moderation ?? ''}
          onChange={(e) => set('moderation', e.target.value || undefined)}
        >
          <option value="">Tous</option>
          <option value="visible">Visibles</option>
          <option value="hidden">Masqués</option>
        </SelectField>
        <TextField
          label="Recherche"
          type="search"
          value={search}
          onChange={(e) => {
            setSearch(e.target.value);
            set('q', e.target.value.trim() || undefined);
          }}
          hint="2 caractères minimum"
        />
      </div>
      {ownerId && (
        <p className="text-muted flex items-center gap-2 text-sm">
          Filtré sur un propriétaire
          <Button size="sm" variant="ghost" onClick={() => set('ownerId', undefined)}>
            Retirer
          </Button>
        </p>
      )}
      {list.isPending ? (
        <div className="flex justify-center py-10">
          <Spinner label="Chargement des contenus" />
        </div>
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => list.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState title="Aucun contenu">Rien ne correspond à ces filtres.</EmptyState>
      ) : (
        <ul className="flex flex-col gap-3">
          {items.map((c) => {
            const target = { type: c.type, id: c.id, label: c.label };
            return (
              <Card key={`${c.type}:${c.id}`}>
                <div className="flex gap-3">
                  <Cover url={c.imageUrl} seed={c.id} className="size-12" />
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-1.5">
                      <Badge>{TARGET_LABEL[c.type]}</Badge>
                      <ModerationBadge moderation={c.moderation} />
                      {c.visibility && <Badge>{VISIBILITY_LABEL[c.visibility]}</Badge>}
                      {c.status && (
                        <Badge tone={c.status === 'failed' ? 'danger' : 'neutral'}>
                          {MEDIA_STATUS_LABEL[c.status]}
                        </Badge>
                      )}
                    </div>
                    <p className="mt-1 line-clamp-2 font-semibold break-words">{c.label}</p>
                    <p className="text-muted text-sm">
                      @{c.owner.username} · {when(c.createdAt)}
                    </p>
                  </div>
                </div>
                <div className="mt-3 flex flex-wrap items-center gap-2">
                  {c.moderation === 'visible' ? (
                    <Button size="sm" variant="danger" onClick={() => decision.ask(target, 'hide')}>
                      Masquer
                    </Button>
                  ) : (
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => decision.ask(target, 'dismiss')}
                    >
                      Rétablir
                    </Button>
                  )}
                  {isAdmin && (
                    <Button size="sm" variant="danger" onClick={() => del.ask(target)}>
                      Supprimer
                    </Button>
                  )}
                  {c.openReportCount > 0 && (
                    <Link
                      to={reportTargetPath({ targetType: c.type, targetId: c.id })}
                      className="text-accent text-sm font-medium hover:underline"
                    >
                      {c.openReportCount} signalement{c.openReportCount > 1 ? 's' : ''} ouvert
                      {c.openReportCount > 1 ? 's' : ''}
                    </Link>
                  )}
                </div>
              </Card>
            );
          })}
        </ul>
      )}
      <InfiniteSentinel
        hasMore={!!list.hasNextPage}
        loading={list.isFetchingNextPage}
        onLoadMore={() => list.fetchNextPage()}
      />
      {decision.node}
      {del.node}
    </section>
  );
}
