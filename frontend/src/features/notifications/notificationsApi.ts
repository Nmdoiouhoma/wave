// W-46: in-app notifications (FB-16, W-45). Kept out of api/endpoints.ts so W-36 can edit it
// concurrently; same client, types aliased from the generated schema.
import { apiClient } from '@/api/instance';
import type { PageParams } from '@/api/endpoints';
import type { components } from '@/api/schema';

type S = components['schemas'];
export type Notification = S['Notification'];
export type NotificationPage = S['NotificationPage'];
export type NotificationType = S['NotificationType'];
export type UnreadNotificationCount = S['UnreadNotificationCount'];
export type MarkNotificationsReadRequest = S['MarkNotificationsReadRequest'];

export const notificationsApi = {
  list: (page: PageParams = {}) =>
    apiClient.request<NotificationPage>('GET', '/me/notifications', { query: { ...page } }),
  unreadCount: () =>
    apiClient.request<UnreadNotificationCount>('GET', '/me/notifications/unread-count'),
  /** `upTo` must be an `updatedAt` string received from the API, unchanged (µs, compared exactly). */
  markRead: (upTo: string) =>
    apiClient.request<undefined>('POST', '/me/notifications/read', {
      body: { upTo } satisfies MarkNotificationsReadRequest,
    }),
};
