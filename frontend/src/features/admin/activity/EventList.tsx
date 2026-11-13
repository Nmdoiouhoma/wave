import { Link } from 'react-router';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/States';
import { AdminError } from '../AdminError';
import { Badge } from '../moderation/parts';
import { CATEGORY_LABEL, type ActivityEvent, type ActivityEventPage } from './activityApi';

const dateTime = new Intl.DateTimeFormat('fr-FR', { dateStyle: 'medium', timeStyle: 'medium' });

/** Metadata is ids, enums, counts and the staff note: shown as plain text, never interpreted. */
function meta(e: ActivityEvent): string {
  return Object.entries(e.metadata)
    .map(([k, v]) => `${k} : ${typeof v === 'object' ? JSON.stringify(v) : String(v)}`)
    .join(' · ');
}

function Who({ label, u }: { label: string; u: ActivityEvent['actor'] }) {
  if (!u) return null;
  return (
    <span>
      {label}{' '}
      <Link to={`/admin/users/${u.id}`} className="text-accent hover:underline">
        @{u.username}
      </Link>
    </span>
  );
}

function Row({ e }: { e: ActivityEvent }) {
  const m = meta(e);
  return (
    <li className="bg-surface border-line flex flex-col gap-1.5 rounded-2xl border p-4">
      <div className="flex flex-wrap items-center gap-2">
        <Badge tone={e.category === 'admin' ? 'warning' : e.category === 'abuse' ? 'danger' : 'neutral'}>
          {CATEGORY_LABEL[e.category]}
        </Badge>
        <code className="text-sm font-medium">{e.type}</code>
        <time dateTime={e.createdAt} className="text-muted ml-auto text-xs">
          {dateTime.format(new Date(e.createdAt))}
        </time>
      </div>
      <p className="text-muted flex flex-wrap gap-x-4 gap-y-0.5 text-sm">
        <Who label="Acteur" u={e.actor} />
        <Who label="Sujet" u={e.subjectUser} />
        {e.targetType && (
          <span>
            Cible {e.targetType}
            {e.targetId ? ` ${e.targetId}` : ''}
          </span>
        )}
      </p>
      {(e.ipPrefix || e.userAgent) && (
        <p className="text-faint text-xs break-words">
          {e.ipPrefix && <span>IP {e.ipPrefix}</span>}
          {e.ipPrefix && e.userAgent && ' · '}
          {e.userAgent && <span>{e.userAgent}</span>}
        </p>
      )}
      {m && <p className="text-sm break-words">{m}</p>}
    </li>
  );
}

export interface EventQuery {
  isPending: boolean;
  isError: boolean;
  error: unknown;
  refetch: () => unknown;
  hasNextPage: boolean;
  isFetchingNextPage: boolean;
  fetchNextPage: () => unknown;
  data?: { pages: ActivityEventPage[] };
}

export function EventList({ q }: { q: EventQuery }) {
  if (q.isPending)
    return (
      <div className="flex justify-center py-10">
        <Spinner label="Chargement du journal" />
      </div>
    );
  if (q.isError) return <AdminError error={q.error} onRetry={() => void q.refetch()} />;
  const items = q.data?.pages.flatMap((p) => p.items) ?? [];
  if (items.length === 0) return <EmptyState title="Journal vide">Aucun événement.</EmptyState>;
  return (
    <>
      <ul className="flex flex-col gap-3">
        {items.map((e) => (
          <Row key={e.id} e={e} />
        ))}
      </ul>
      <InfiniteSentinel
        hasMore={q.hasNextPage}
        loading={q.isFetchingNextPage}
        onLoadMore={() => void q.fetchNextPage()}
      />
    </>
  );
}
