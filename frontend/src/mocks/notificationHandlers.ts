// W-46: default notification handlers (no notification in the mock db). Tests override them
// with `server.use(...)` and their own fixtures.
import { http } from 'msw';
import type { components } from '@/api/schema';
import { json, noContent, requireUser } from './handlerUtils';

const API = '*/api/v1';
type S = components['schemas'];

export const notificationHandlers = [
  http.get(`${API}/me/notifications`, ({ request }) => {
    requireUser(request);
    return json({ items: [], nextCursor: null } satisfies S['NotificationPage']);
  }),
  http.get(`${API}/me/notifications/unread-count`, ({ request }) => {
    requireUser(request);
    return json({ unreadCount: 0 } satisfies S['UnreadNotificationCount']);
  }),
  http.post(`${API}/me/notifications/read`, ({ request }) => {
    requireUser(request);
    return noContent();
  }),
];
