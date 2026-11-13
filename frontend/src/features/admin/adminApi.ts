// W-73: back-office API (ADR 013). Kept out of api/endpoints.ts; W-74/W-75 add their own files.
import { apiClient } from '@/api/instance';
import type { PageParams } from '@/api/endpoints';
import type { components } from '@/api/schema';

type S = components['schemas'];
export type AdminDashboard = S['AdminDashboard'];
export type AdminUser = S['AdminUser'];
export type AdminUserDetail = S['AdminUserDetail'];
export type AdminUserPage = S['AdminUserPage'];
export type AdminRole = S['AdminRole'];
export type SetRoleRequest = S['SetRoleRequest'];
export type AdminNoteRequest = S['AdminNoteRequest'];

export type AdminUserStatus = 'active' | 'suspended' | 'unverified';
export interface AdminUserFilters {
  q?: string;
  role?: AdminRole;
  status?: AdminUserStatus;
}

const enc = encodeURIComponent;
const r = apiClient.request.bind(apiClient);

export const adminApi = {
  dashboard: () => r<AdminDashboard>('GET', '/admin/dashboard'),
  users: (filters: AdminUserFilters, page: PageParams = {}) =>
    r<AdminUserPage>('GET', '/admin/users', { query: { ...filters, ...page } }),
  user: (id: string) => r<AdminUserDetail>('GET', `/admin/users/${enc(id)}`),
  suspend: (id: string, note: string) =>
    r<AdminUserDetail>('POST', `/admin/users/${enc(id)}/suspension`, {
      body: { note } satisfies AdminNoteRequest,
    }),
  // Body optional in the contract (W-71); the UI always sends a note.
  unsuspend: (id: string, note: string) =>
    r<AdminUserDetail>('DELETE', `/admin/users/${enc(id)}/suspension`, {
      body: { note } satisfies AdminNoteRequest,
    }),
  setRole: (id: string, body: SetRoleRequest) =>
    r<AdminUserDetail>('PUT', `/admin/users/${enc(id)}/role`, { body }),
};
