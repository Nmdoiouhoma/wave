// FB-18, ADR 012: the single close-friends list, picked among the caller's followers.
import { Link } from 'react-router';
import { useCloseFriendFollowers, useToggleCloseFriend } from '@/api/queries/closeFriends';
import type { CloseFriendItem } from '@/api/types';
import { StarIcon, UserIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Avatar } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { cx } from '@/components/ui/cx';

function FriendRow({ user }: { user: CloseFriendItem }) {
  const toggle = useToggleCloseFriend();
  const on = user.isCloseFriend;
  // Errors are toasted by the hook: on 422 `not_a_follower` this row unmounts before a
  // per-call callback could run.
  const onClick = () => toggle.mutate({ username: user.username, add: !on });
  return (
    <li className="hover:bg-surface-2 flex items-center gap-3 rounded-xl px-2 py-2">
      <Link to={`/u/${user.username}`} className="flex min-w-0 flex-1 items-center gap-3">
        <Avatar
          url={user.avatarUrl}
          name={user.displayName}
          seed={user.id}
          className="size-11 text-sm"
        />
        <span className="min-w-0">
          <span className="block truncate font-semibold">{user.displayName}</span>
          <span className="text-muted block truncate text-xs">@{user.username}</span>
        </span>
      </Link>
      <button
        type="button"
        role="switch"
        aria-checked={on}
        aria-label={`${user.displayName} dans mes amis proches`}
        disabled={toggle.isPending}
        onClick={onClick}
        className={cx(
          'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition',
          on ? 'bg-accent/15 text-accent-strong ring-accent' : 'bg-surface-2 text-muted ring-line',
        )}
      >
        <StarIcon size={13} fill={on ? 'currentColor' : 'none'} />
        {on ? 'Ami proche' : 'Ajouter'}
      </button>
    </li>
  );
}

export function CloseFriendsPage() {
  const list = useCloseFriendFollowers();
  const users = list.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <div className="mx-auto max-w-xl">
      <Link to="/settings" className="text-muted hover:text-fg text-sm">
        ← Paramètres
      </Link>
      <h1 className="mt-3 text-2xl font-bold tracking-tight">Amis proches</h1>
      <p className="text-muted mt-1 text-sm">
        Choisis parmi tes abonnés qui peut écouter tes morceaux « Amis proches ». Une seule liste,
        valable pour tous ces morceaux. Si quelqu’un se désabonne, il en sort.
      </p>
      <div className="mt-4">
        {list.isPending ? (
          <PageSpinner />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => void list.refetch()} />
        ) : users.length ? (
          <>
            <ul aria-label="Tes abonnés" className="flex flex-col">
              {users.map((u) => (
                <FriendRow key={u.id} user={u} />
              ))}
            </ul>
            <InfiniteSentinel
              hasMore={!!list.hasNextPage}
              loading={list.isFetchingNextPage}
              onLoadMore={() => void list.fetchNextPage()}
            />
          </>
        ) : (
          <EmptyState icon={<UserIcon size={28} />} title="Personne ne te suit encore">
            <p>Tes amis proches se choisissent parmi tes abonnés.</p>
          </EmptyState>
        )}
      </div>
    </div>
  );
}
