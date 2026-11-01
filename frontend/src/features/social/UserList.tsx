import { Link } from 'react-router';
import { useFollowState } from '@/api/queries/social';
import type { UserListItem } from '@/api/types';
import { Avatar } from '@/components/ui/Media';
import { formatCount } from '@/lib/format';
import { FollowButton } from './FollowButton';

function UserRow({ user }: { user: UserListItem }) {
  const server = { following: user.isFollowedByMe, followersCount: user.followersCount };
  const state = useFollowState(user.username, server);
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
          <span className="text-muted block truncate text-xs">
            @{user.username} · {formatCount(state.followersCount)} abonné
            {state.followersCount > 1 ? 's' : ''}
          </span>
        </span>
      </Link>
      {!user.isMe && (
        <FollowButton username={user.username} displayName={user.displayName} server={server} />
      )}
    </li>
  );
}

export function UserList({ users, label }: { users: UserListItem[]; label: string }) {
  return (
    <ul aria-label={label} className="flex flex-col">
      {users.map((u) => (
        <UserRow key={u.id} user={u} />
      ))}
    </ul>
  );
}
