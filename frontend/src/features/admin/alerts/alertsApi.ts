// W-75: security alerts (/admin/alerts*).
import { apiClient } from '@/api/instance';
import type { components } from '@/api/schema';

type S = components['schemas'];
export type SecurityAlert = S['SecurityAlert'];
export type SecurityAlertPage = S['SecurityAlertPage'];
export type AlertStatus = S['AlertStatus'];
export type AlertSeverity = S['AlertSeverity'];
export type AlertRule = S['AlertRule'];

const { request } = apiClient;

export const RULE_LABEL: Record<AlertRule, string> = {
  login_failures: 'Échecs de connexion',
  token_reuse: 'Réutilisation de jeton',
  mass_registration: 'Inscriptions en masse',
  report_burst: 'Rafale de signalements',
  report_spree: 'Signalements en série',
  upload_abuse: 'Imports abusifs',
  rate_limit_hammering: 'Limites de débit martelées',
  privilege_granted: 'Privilèges accordés',
};
export const SEVERITY_LABEL: Record<AlertSeverity, string> = {
  low: 'Faible',
  medium: 'Moyenne',
  high: 'Élevée',
};
export const STATUS_LABEL: Record<AlertStatus, string> = {
  open: 'Ouvertes',
  acknowledged: 'Acquittées',
};

export const alertsApi = {
  list: (
    f: { status: AlertStatus; severity?: AlertSeverity; rule?: AlertRule },
    cursor: string | null,
  ) => request<SecurityAlertPage>('GET', '/admin/alerts', { query: { ...f, cursor, limit: 20 } }),
  acknowledge: (id: string, note: string) =>
    request<SecurityAlert>('POST', `/admin/alerts/${encodeURIComponent(id)}/acknowledgement`, {
      body: { note },
    }),
};
