import { act, screen, waitFor, within } from '@testing-library/react';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { json, noContent, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { loginAs, renderRoute } from '@/test/utils';
import { NotificationBell } from './NotificationBell';
import type { Notification } from './notificationsApi';
import { NotificationsPage } from './NotificationsPage';
import {
  badgeText,
  notificationHref,
  readUpTo,
  relativeTime,
  sentenceText,
} from './notificationText';
import { useUnreadCount } from './queries';

const API = '*/api/v1';
const NEWEST = '2026-11-12T10:19:20.006574+00:00';
const user = (n: string, i: number) => ({
  id: `0192dddd-0000-7000-8000-00000000000${i}`,
  username: n.toLowerCase(),
  displayName: n,
  avatarUrl: null,
});
const ALICE = user('Alice', 1);
const BOB = user('Bob', 2);
const CHLOE = user('Chloé', 3);

function notif(over: Partial<Notification> = {}): Notification {
  return {
    id: '0192eeee-0000-7000-8000-000000000001',
    type: 'follow',
    actors: [ALICE],
    actorCount: 1,
    target: null,
    read: false,
    updatedAt: NEWEST,
    ...over,
  };
}
const track = (label: string) => ({
  type: 'track' as const,
  id: '0192cccc-0000-7000-8000-000000000001',
  label,
  imageUrl: null,
});

describe('notification wording and links', () => {
  it.each<[string, Notification, string, string]>([
    ['follow', notif(), 'Alice a commencé à te suivre', '/u/alice'],
    [
      'two likers',
      notif({ type: 'track_like', actors: [ALICE, BOB], actorCount: 2, target: track('X') }),
      'Alice et Bob ont aimé ton morceau « X »',
      '/t/0192cccc-0000-7000-8000-000000000001',
    ],
    [
      'many likers',
      notif({
        type: 'track_like',
        actors: [ALICE, BOB, CHLOE],
        actorCount: 3,
        target: track('X'),
      }),
      'Alice et 2 autres ont aimé ton morceau « X »',
      '/t/0192cccc-0000-7000-8000-000000000001',
    ],
    [
      'playlist',
      notif({
        type: 'playlist_like',
        target: { type: 'playlist', id: 'p1', label: 'Été', imageUrl: null },
      }),
      'Alice a aimé ta playlist « Été »',
      '/p/p1',
    ],
    [
      'comments',
      notif({
        type: 'comment',
        actors: [ALICE, BOB],
        actorCount: 5,
        target: { type: 'post', id: 'po1', label: 'Mon son', imageUrl: null },
      }),
      'Alice et 4 autres ont commenté ton post « Mon son »',
      '/posts/po1',
    ],
  ])('%s', (_name, n, text, href) => {
    expect(sentenceText(n)).toBe(text);
    expect(notificationHref(n)).toBe(href);
  });

  it('shows 99+ from 100 and nothing at 0', () => {
    expect([0, 1, 99, 100].map(badgeText)).toEqual([null, '1', '99', '99+']);
  });

  it('parses microsecond dates for display only', () => {
    const now = Date.parse('2026-11-12T10:24:30Z');
    expect(relativeTime(NEWEST, now)).toBe('il y a 5 min');
    expect(relativeTime('2026-11-12T10:24:10.999999+00:00', now)).toBe('à l’instant');
  });

  it('keeps the newest updatedAt string as is for `upTo` (never re-serialized)', () => {
    const upTo = readUpTo([
      {
        items: [notif(), notif({ updatedAt: '2026-11-01T00:00:00.000001+00:00' })],
        nextCursor: null,
      },
    ]);
    expect(upTo).toBe(NEWEST);
    // What a Date round trip would send: the 574 µs are lost and the newest item stays unread.
    expect(new Date(NEWEST).toISOString()).not.toBe(upTo);
  });
});

describe('notifications page and bell (W-46)', () => {
  beforeEach(async () => {
    await loginAs('lea@wave.local');
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('marks read with the exact newest updatedAt and resets the badge optimistically', async () => {
    let unread = 3;
    const bodies: unknown[] = [];
    let releaseList!: () => void;
    const listGate = new Promise<void>((r) => (releaseList = r));
    let releaseRead!: () => void;
    const readGate = new Promise<void>((r) => (releaseRead = r));
    server.use(
      http.get(`${API}/me/notifications/unread-count`, () => json({ unreadCount: unread })),
      http.get(`${API}/me/notifications`, async () => {
        await listGate;
        return json({
          items: [
            notif({
              type: 'track_like',
              actors: [ALICE, BOB, CHLOE],
              actorCount: 3,
              target: track('<b>X</b>'),
            }),
            notif({
              id: '0192eeee-0000-7000-8000-000000000002',
              read: true,
              updatedAt: '2026-11-10T08:00:00.000001+00:00',
            }),
          ],
          nextCursor: null,
        });
      }),
      http.post(`${API}/me/notifications/read`, async ({ request }) => {
        bodies.push(await request.json());
        await readGate;
        unread = 0;
        return noContent();
      }),
    );
    renderRoute(
      <>
        <NotificationBell />
        <NotificationsPage />
      </>,
      { path: '/notifications' },
    );
    expect(await screen.findByTestId('unread-badge')).toHaveTextContent('3');
    expect(screen.getByRole('link', { name: 'Notifications, 3 non lues' })).toBeInTheDocument();
    releaseList();

    const list = await screen.findByRole('list', { name: 'Notifications' });
    await waitFor(() => expect(bodies).toEqual([{ upTo: NEWEST }]));
    // Badge gone while the request is still pending.
    expect(screen.queryByTestId('unread-badge')).not.toBeInTheDocument();
    releaseRead();

    const [first, second] = within(list).getAllByRole('link');
    expect(first).toHaveAccessibleName(
      'Alice et 2 autres ont aimé ton morceau « <b>X</b> », non lue',
    );
    expect(first).toHaveAttribute('href', '/t/0192cccc-0000-7000-8000-000000000001');
    expect(first!.querySelector('b')).toBeNull();
    expect(within(first!).getByText(/« <b>X<\/b> »/)).toBeInTheDocument();
    expect(second).toHaveAccessibleName('Alice a commencé à te suivre');
    expect(second).toHaveAttribute('href', '/u/alice');
    await waitFor(() => expect(screen.queryByTestId('unread-badge')).not.toBeInTheDocument());
    expect(bodies).toHaveLength(1);
  });

  it('shows the empty state and sends no read', async () => {
    const read = vi.fn();
    server.use(
      http.post(`${API}/me/notifications/read`, () => {
        read();
        return noContent();
      }),
    );
    renderRoute(<NotificationsPage />, { path: '/notifications' });
    expect(await screen.findByText('Aucune notification pour l’instant')).toBeInTheDocument();
    expect(read).not.toHaveBeenCalled();
  });

  it('shows the error state', async () => {
    server.use(http.get(`${API}/me/notifications`, () => problem(500, 'internal_error')));
    renderRoute(<NotificationsPage />, { path: '/notifications' });
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });

  it('polls every 60 s while visible, not while hidden, and again on focus', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true });
    let calls = 0;
    server.use(
      http.get(`${API}/me/notifications/unread-count`, () => {
        calls += 1;
        return json({ unreadCount: 120 });
      }),
    );
    function Shell() {
      useUnreadCount({ poll: true });
      return <NotificationBell />;
    }
    renderRoute(<Shell />);
    expect(await screen.findByTestId('unread-badge')).toHaveTextContent('99+');
    expect(calls).toBe(1);

    await act(() => vi.advanceTimersByTimeAsync(60_000));
    await waitFor(() => expect(calls).toBe(2));

    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    act(() => void window.dispatchEvent(new Event('visibilitychange')));
    await act(() => vi.advanceTimersByTimeAsync(180_000));
    expect(calls).toBe(2);

    visibility.mockReturnValue('visible');
    act(() => void window.dispatchEvent(new Event('visibilitychange')));
    await waitFor(() => expect(calls).toBe(3));
  });
});
