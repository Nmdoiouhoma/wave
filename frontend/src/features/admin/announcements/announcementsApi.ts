// W-75: announcements (/admin/announcements*, public /announcements).
import { apiClient } from '@/api/instance';
import type { components } from '@/api/schema';

type S = components['schemas'];
export type Announcement = S['Announcement'];
export type AnnouncementPage = S['AnnouncementPage'];
export type AnnouncementLevel = S['AnnouncementLevel'];
export type CreateAnnouncement = S['CreateAnnouncementRequest'];
export type UpdateAnnouncement = S['UpdateAnnouncementRequest'];

const { request } = apiClient;

export const announcementsApi = {
  active: () => request<S['ActiveAnnouncements']>('GET', '/announcements'),
  list: (cursor: string | null) =>
    request<AnnouncementPage>('GET', '/admin/announcements', { query: { cursor, limit: 20 } }),
  create: (body: CreateAnnouncement) =>
    request<Announcement>('POST', '/admin/announcements', { body }),
  update: (id: string, body: UpdateAnnouncement) =>
    request<Announcement>('PATCH', `/admin/announcements/${encodeURIComponent(id)}`, { body }),
  remove: (id: string) =>
    request<undefined>('DELETE', `/admin/announcements/${encodeURIComponent(id)}`),
};
