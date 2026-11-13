// W-75: journal operations of docs/openapi.yaml (/admin/users/{id}/activity, /admin/audit-log).
import { apiClient } from '@/api/instance';
import type { components } from '@/api/schema';

type S = components['schemas'];
export type ActivityEvent = S['ActivityEvent'];
export type ActivityEventPage = S['ActivityEventPage'];
export type ActivityCategory = S['ActivityCategory'];

const { request } = apiClient;

export const CATEGORY_LABEL: Record<ActivityCategory, string> = {
  auth: 'Connexion',
  account: 'Compte',
  content: 'Contenu',
  report: 'Signalement',
  upload: 'Import',
  abuse: 'Abus',
  admin: 'Administration',
};

export const activityApi = {
  user: (userId: string, category: ActivityCategory | undefined, cursor: string | null) =>
    request<ActivityEventPage>('GET', `/admin/users/${encodeURIComponent(userId)}/activity`, {
      query: { category, cursor, limit: 20 },
    }),
  audit: (actorId: string | undefined, cursor: string | null) =>
    request<ActivityEventPage>('GET', '/admin/audit-log', {
      query: { actorId, cursor, limit: 20 },
    }),
};
