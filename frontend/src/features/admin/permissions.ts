// ADR 013 §2, role matrix. Pure helpers: the API stays the authority (403), these only hide buttons.
import type { Me } from '@/api/types';
import type { AdminRole, AdminUser } from './adminApi';

export type Roles = Me['roles'];

export const isAdmin = (roles: Roles) => roles.includes('ROLE_ADMIN');
export const isStaff = (roles: Roles) => isAdmin(roles) || roles.includes('ROLE_MODERATOR');

export const ROLE_LABELS: Record<AdminRole, string> = {
  ROLE_USER: 'Utilisateur',
  ROLE_MODERATOR: 'Modérateur',
  ROLE_ADMIN: 'Administrateur',
};

/** Suspend / unsuspend: moderator → plain users only; admin → users and moderators; never self. */
export function canSuspend(viewer: Roles, viewerId: string, target: Pick<AdminUser, 'id' | 'role'>) {
  if (viewerId === target.id || target.role === 'ROLE_ADMIN') return false;
  if (isAdmin(viewer)) return true;
  return isStaff(viewer) && target.role === 'ROLE_USER';
}

/** Role change: admin only, never self, never an admin. */
export function canSetRole(viewer: Roles, viewerId: string, target: Pick<AdminUser, 'id' | 'role'>) {
  return isAdmin(viewer) && viewerId !== target.id && target.role !== 'ROLE_ADMIN';
}
