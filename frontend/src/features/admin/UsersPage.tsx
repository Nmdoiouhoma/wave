// W-73: users list, search (username / display name; e-mail for admins), role and status filters, cursor paging.
import { Link, useSearchParams } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { Avatar } from '@/components/ui/Media';
import { SearchIcon } from '@/components/ui/icons';
import { SelectField, TextField } from '@/components/ui/Field';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/States';
import { useDebounced } from '@/lib/useDebounced';
import { useState } from 'react';
import { AdminError } from './AdminError';
import type { AdminRole, AdminUserStatus } from './adminApi';
import { UserBadges } from './badges';
import { isAdmin, ROLE_LABELS } from './permissions';
import { useAdminUsers } from './queries';

const ROLES = Object.keys(ROLE_LABELS) as AdminRole[];
const STATUSES: { value: AdminUserStatus; label: string }[] = [
  { value: 'active', label: 'Actifs' },
  { value: 'suspended', label: 'Suspendus' },
  { value: 'unverified', label: 'Non vérifiés' },
];

export function UsersPage() {
  const me = useCurrentUser();
  const [params, setParams] = useSearchParams();
  const [text, setText] = useState(params.get('q') ?? '');
  const q = useDebounced(text.trim(), 300);
  const roleParam = params.get('role');
  const statusParam = params.get('status');
  const role = ROLES.find((r) => r === roleParam);
  const status = STATUSES.find((s) => s.value === statusParam)?.value;

  const setParam = (key: string, value: string) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        if (value) next.set(key, value);
        else next.delete(key);
        return next;
      },
      { replace: true },
    );

  const list = useAdminUsers({ ...(q ? { q } : {}), ...(role ? { role } : {}), ...(status ? { status } : {}) });
  const items = list.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-2xl font-bold tracking-tight">Utilisateurs</h1>
      <div className="grid gap-3 sm:grid-cols-[1fr_11rem_11rem]">
        <TextField
          label="Rechercher"
          type="search"
          value={text}
          onChange={(e) => {
            setText(e.target.value);
            setParam('q', e.target.value.trim());
          }}
          maxLength={180}
          placeholder={isAdmin(me.roles) ? 'Nom, pseudo ou e-mail exact' : 'Nom ou pseudo'}
        />
        <SelectField label="Rôle" value={role ?? ''} onChange={(e) => setParam('role', e.target.value)}>
          <option value="">Tous</option>
          {ROLES.map((r) => (
            <option key={r} value={r}>
              {ROLE_LABELS[r]}
            </option>
          ))}
        </SelectField>
        <SelectField label="Statut" value={status ?? ''} onChange={(e) => setParam('status', e.target.value)}>
          <option value="">Tous</option>
          {STATUSES.map((s) => (
            <option key={s.value} value={s.value}>
              {s.label}
            </option>
          ))}
        </SelectField>
      </div>

      {list.isPending ? (
        <Spinner label="Chargement des utilisateurs" className="mx-auto mt-10" />
      ) : list.isError ? (
        <AdminError error={list.error} onRetry={() => void list.refetch()} />
      ) : items.length === 0 ? (
        <EmptyState icon={<SearchIcon size={24} />} title="Aucun utilisateur trouvé" />
      ) : (
        <ul className="bg-surface border-line divide-line divide-y rounded-[20px] border">
          {items.map((u) => (
            <li key={u.id}>
              <Link
                to={`/admin/users/${encodeURIComponent(u.id)}`}
                className="hover:bg-surface-2 flex min-h-16 items-center gap-3 px-4 py-3 first:rounded-t-[20px] last:rounded-b-[20px]"
              >
                <Avatar url={u.avatarUrl} name={u.displayName} seed={u.id} className="size-10 text-sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate font-medium">{u.displayName}</span>
                  <span className="text-muted block truncate text-sm">
                    @{u.username}
                    {u.email ? ` · ${u.email}` : ''}
                  </span>
                </span>
                <span className="flex flex-wrap justify-end gap-1.5">
                  <UserBadges user={u} />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      <InfiniteSentinel
        hasMore={!!list.hasNextPage}
        loading={list.isFetchingNextPage}
        onLoadMore={() => void list.fetchNextPage()}
      />
    </div>
  );
}
