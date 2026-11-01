import { Link, useParams } from 'react-router';
import { useFollowList } from '@/api/queries/social';
import { useUserProfile } from '@/api/queries/users';
import { cx } from '@/components/ui/cx';
import { UserIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { UserList } from './UserList';

export function FollowListPage({ which }: { which: 'followers' | 'following' }) {
  const { username = '' } = useParams();
  const profile = useUserProfile(username);
  const list = useFollowList(username, which);
  const name = profile.data?.displayName ?? `@${username}`;
  const users = list.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <div className="mx-auto max-w-xl">
      <Link to={`/u/${username}`} className="text-muted hover:text-fg text-sm">
        ← {name}
      </Link>
      <nav aria-label="Abonnements" className="border-line mt-3 flex gap-1 border-b">
        {(
          [
            ['followers', 'Abonnés'],
            ['following', 'Abonnements'],
          ] as const
        ).map(([value, label]) => (
          <Link
            key={value}
            to={`/u/${username}/${value}`}
            aria-current={which === value ? 'page' : undefined}
            className={cx(
              '-mb-px border-b-2 px-4 py-2.5 text-sm font-semibold',
              which === value
                ? 'border-accent text-fg'
                : 'text-muted hover:text-fg border-transparent',
            )}
          >
            {label}
          </Link>
        ))}
      </nav>
      <h1 className="sr-only">
        {which === 'followers' ? `Abonnés de ${name}` : `Abonnements de ${name}`}
      </h1>
      <div className="mt-3">
        {list.isPending ? (
          <PageSpinner />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => void list.refetch()} />
        ) : users.length ? (
          <>
            <UserList users={users} label={which === 'followers' ? 'Abonnés' : 'Abonnements'} />
            <InfiniteSentinel
              hasMore={!!list.hasNextPage}
              loading={list.isFetchingNextPage}
              onLoadMore={() => void list.fetchNextPage()}
            />
          </>
        ) : (
          <EmptyState
            icon={<UserIcon size={28} />}
            title={
              which === 'followers'
                ? 'Personne ne suit ce compte pour l’instant'
                : 'Ce compte ne suit personne'
            }
          />
        )}
      </div>
    </div>
  );
}
