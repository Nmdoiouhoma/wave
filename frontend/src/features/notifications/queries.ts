// W-46: notification queries. Keys under ['me', 'notifications'] (CONVENTIONS §3).
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { notificationsApi, type UnreadNotificationCount } from './notificationsApi';
import { badgeText } from './notificationText';

export const notificationKeys = {
  list: () => ['me', 'notifications', 'list'] as const,
  unreadCount: () => ['me', 'notifications', 'unread-count'] as const,
};

export const POLL_MS = 60_000;

/**
 * Unread count. Only the shell's poller passes `poll`: every 60 s while the page is visible
 * (TanStack pauses `refetchInterval` when the document is hidden) and again on focus.
 * The bells only read the shared cache.
 */
export function useUnreadCount({ poll = false }: { poll?: boolean } = {}) {
  return useQuery({
    queryKey: notificationKeys.unreadCount(),
    queryFn: notificationsApi.unreadCount,
    staleTime: poll ? 0 : POLL_MS,
    refetchInterval: poll ? POLL_MS : false,
    refetchIntervalInBackground: false,
    refetchOnWindowFocus: poll,
    retry: false,
  });
}

export function useNotifications() {
  return useInfiniteQuery({
    queryKey: notificationKeys.list(),
    queryFn: ({ pageParam }) => notificationsApi.list({ cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
    // Each visit shows the current state (the highlight of the previous visit is gone).
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
}

/** Marks read up to `upTo` (sent unchanged); the badge goes to 0 right away. */
export function useMarkRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (upTo: string) => notificationsApi.markRead(upTo),
    onMutate: async () => {
      await qc.cancelQueries({ queryKey: notificationKeys.unreadCount() });
      const previous = qc.getQueryData<UnreadNotificationCount>(notificationKeys.unreadCount());
      qc.setQueryData<UnreadNotificationCount>(notificationKeys.unreadCount(), { unreadCount: 0 });
      return { previous };
    },
    onError: (_e, _upTo, ctx) =>
      qc.setQueryData(notificationKeys.unreadCount(), ctx?.previous ?? { unreadCount: 0 }),
    // Events that arrived meanwhile stay unread: take the server count back.
    onSettled: () => qc.invalidateQueries({ queryKey: notificationKeys.unreadCount() }),
  });
}

/** Accessible name of the bell: "Notifications, 3 non lues". */
export function useBellLabel(): string {
  const count = useUnreadCount().data?.unreadCount ?? 0;
  const text = badgeText(count);
  if (!text) return 'Notifications';
  return `Notifications, ${text} ${count > 1 ? 'non lues' : 'non lue'}`;
}
