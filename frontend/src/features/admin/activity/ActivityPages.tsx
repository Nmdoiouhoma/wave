import { useInfiniteQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Link, useParams } from 'react-router';
import { SelectField } from '@/components/ui/Field';
import { activityApi, CATEGORY_LABEL, type ActivityCategory } from './activityApi';
import { EventList } from './EventList';

const first = { initialPageParam: null as string | null };

export function UserActivityPage() {
  const { userId = '' } = useParams();
  const [category, setCategory] = useState<ActivityCategory | ''>('');
  const q = useInfiniteQuery({
    queryKey: ['admin', 'activity', userId, category],
    queryFn: ({ pageParam }) => activityApi.user(userId, category || undefined, pageParam),
    getNextPageParam: (l) => l.nextCursor,
    ...first,
  });
  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <Link to={`/admin/users/${userId}`} className="text-muted hover:text-fg w-fit text-sm">
        ← Fiche du compte
      </Link>
      <h1 className="text-2xl font-semibold">Journal d’activité</h1>
      <p className="text-muted text-sm">
        Événements dont ce compte est l’auteur ou le sujet. L’ouverture de cette page est elle-même
        inscrite au journal d’audit.
      </p>
      <SelectField
        label="Catégorie"
        value={category}
        onChange={(e) => setCategory(e.target.value as ActivityCategory | '')}
      >
        <option value="">Toutes</option>
        {(Object.keys(CATEGORY_LABEL) as ActivityCategory[]).map((c) => (
          <option key={c} value={c}>
            {CATEGORY_LABEL[c]}
          </option>
        ))}
      </SelectField>
      <EventList q={q} />
    </section>
  );
}

export function AuditLogPage() {
  const q = useInfiniteQuery({
    queryKey: ['admin', 'audit-log'],
    queryFn: ({ pageParam }) => activityApi.audit(undefined, pageParam),
    getNextPageParam: (l) => l.nextCursor,
    ...first,
  });
  return (
    <section className="flex w-full max-w-3xl flex-col gap-4">
      <h1 className="text-2xl font-semibold">Journal d’audit</h1>
      <p className="text-muted text-sm">Actions de l’équipe d’administration, conservées un an.</p>
      <EventList q={q} />
    </section>
  );
}
