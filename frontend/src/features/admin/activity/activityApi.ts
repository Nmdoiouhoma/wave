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

/** French labels for the closed list of journal types (DATA_MODEL §15); unknown codes fall back to the raw code. */
const TYPE_LABEL: Record<string, string> = {
  'auth.login_succeeded': 'Connexion réussie',
  'auth.login_failed': 'Échec de connexion',
  'auth.logout': 'Déconnexion',
  'auth.token_reused': 'Jeton de session réutilisé',
  'auth.password_changed': 'Mot de passe modifié',
  'auth.password_reset_requested': 'Réinitialisation du mot de passe demandée',
  'auth.password_reset_completed': 'Mot de passe réinitialisé',
  'account.registered': 'Compte créé',
  'account.email_verified': 'E-mail vérifié',
  'account.email_changed': 'E-mail modifié',
  'content.track_created': 'Morceau créé',
  'content.track_deleted': 'Morceau supprimé',
  'content.post_created': 'Post publié',
  'content.post_deleted': 'Post supprimé',
  'content.comment_created': 'Commentaire publié',
  'content.artist_created': 'Artiste créé',
  'report.created': 'Signalement créé',
  'report.auto_hidden': 'Contenu masqué automatiquement',
  'upload.rejected': 'Import refusé',
  'abuse.rate_limited': 'Limite de requêtes atteinte',
  'admin.user_suspended': 'Compte suspendu',
  'admin.user_unsuspended': 'Suspension levée',
  'admin.role_changed': 'Rôle modifié',
  'admin.content_hidden': 'Contenu masqué',
  'admin.content_restored': 'Contenu rétabli',
  'admin.content_deleted': 'Contenu supprimé',
  'admin.report_decided': 'Décision sur un signalement',
  'admin.alert_acknowledged': 'Alerte prise en compte',
  'admin.announcement_created': 'Annonce créée',
  'admin.announcement_updated': 'Annonce modifiée',
  'admin.announcement_deleted': 'Annonce supprimée',
  'admin.journal_viewed': 'Journal consulté',
  'admin.admin_granted': 'Droits administrateur accordés',
  'admin.admin_revoked': 'Droits administrateur retirés',
};

export const eventTypeLabel = (type: string): string => TYPE_LABEL[type] ?? type;

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
