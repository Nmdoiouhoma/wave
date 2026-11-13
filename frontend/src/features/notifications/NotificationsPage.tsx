// W-46: `/notifications` (no active tab). Opening it marks read up to the newest displayed item.
import { useEffect, useRef } from 'react';
import { Link } from 'react-router';
import { cx } from '@/components/ui/cx';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Avatar, Cover } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { BellIcon } from './NotificationBell';
import type { Notification } from './notificationsApi';
import {
  dedupe,
  notificationHref,
  readUpTo,
  relativeTime,
  sentence,
  sentenceText,
} from './notificationText';
import { useMarkRead, useNotifications } from './queries';

function Avatars({ n }: { n: Notification }) {
  const [a, b] = n.actors;
  if (!a) return <span className="size-11 shrink-0" />;
  if (!b)
    return (
      <Avatar url={a.avatarUrl} name={a.displayName} seed={a.id} className="size-11 text-sm" />
    );
  return (
    <span className="relative size-11 shrink-0">
      <Avatar
        url={b.avatarUrl}
        name={b.displayName}
        seed={b.id}
        className="absolute top-0 right-0 size-8 text-[10px]"
      />
      <Avatar
        url={a.avatarUrl}
        name={a.displayName}
        seed={a.id}
        className="ring-bg absolute bottom-0 left-0 size-8 text-[10px] ring-2"
      />
    </span>
  );
}

function Item({ n }: { n: Notification }) {
  const s = sentence(n);
  return (
    <li>
      <Link
        to={notificationHref(n)}
        aria-label={`${sentenceText(n)}${n.read ? '' : ', non lue'}`}
        className={cx(
          'flex items-center gap-3 rounded-xl px-3 py-3 transition',
          n.read ? 'hover:bg-surface' : 'bg-surface-2/70 hover:bg-surface-2',
        )}
      >
        <Avatars n={n} />
        <span className="min-w-0 flex-1 text-sm">
          <span className="line-clamp-3 break-words">
            <strong className="font-semibold">{s.names[0]}</strong>
            {s.names[1] !== undefined && (
              <>
                {' et '}
                <strong className="font-semibold">{s.names[1]}</strong>
              </>
            )}{' '}
            {s.rest}
          </span>
          <time dateTime={n.updatedAt} className="text-faint mt-0.5 block text-xs">
            {relativeTime(n.updatedAt)}
          </time>
        </span>
        {n.target && <Cover url={n.target.imageUrl} seed={n.target.id} className="size-11" />}
        {!n.read && <span aria-hidden className="bg-accent size-2 shrink-0 rounded-full" />}
      </Link>
    </li>
  );
}

export function NotificationsPage() {
  const list = useNotifications();
  const markRead = useMarkRead();
  const items = dedupe(list.data?.pages);
  // Only once this visit's fetch is in: a cached list from a previous visit is stale.
  const upTo = list.isFetchedAfterMount && !list.isError ? readUpTo(list.data?.pages) : null;

  // Once per newest displayed item; the string goes back exactly as received.
  const sent = useRef<string | null>(null);
  const { mutate } = markRead;
  useEffect(() => {
    if (!upTo || sent.current === upTo) return;
    sent.current = upTo;
    mutate(upTo);
  }, [upTo, mutate]);

  return (
    <div className="mx-auto max-w-xl">
      <h1 className="text-2xl font-bold tracking-tight">Notifications</h1>
      <div className="mt-4">
        {list.isPending ? (
          <PageSpinner />
        ) : list.isError ? (
          <ErrorState error={list.error} onRetry={() => void list.refetch()} />
        ) : items.length ? (
          <>
            <ul aria-label="Notifications" className="flex flex-col gap-1">
              {items.map((n) => (
                <Item key={n.id} n={n} />
              ))}
            </ul>
            <InfiniteSentinel
              hasMore={!!list.hasNextPage}
              loading={list.isFetchingNextPage}
              onLoadMore={() => void list.fetchNextPage()}
            />
          </>
        ) : (
          <EmptyState icon={<BellIcon size={28} />} title="Aucune notification pour l’instant">
            <p>Quand quelqu’un te suit, aime ou commente ce que tu publies, tu le verras ici.</p>
          </EmptyState>
        )}
      </div>
    </div>
  );
}
