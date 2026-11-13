// W-73: entries of the back-office menu. Other lots (W-74 moderation, W-75 journal/alerts/announcements)
// add their entry here and their route in ./adminRoutes.tsx. `adminOnly` entries are hidden for moderators.
export interface AdminNavEntry {
  to: string;
  label: string;
  adminOnly?: boolean;
  /** Exact match only (the dashboard). */
  end?: boolean;
}

export const ADMIN_NAV: AdminNavEntry[] = [
  { to: '/admin', label: 'Tableau de bord', end: true },
  { to: '/admin/users', label: 'Utilisateurs' },
  // W-74
  { to: '/admin/reports', label: 'Signalements' },
  { to: '/admin/content', label: 'Contenus' },
  { to: '/admin/audit-log', label: 'Journal d’audit', adminOnly: true },
  { to: '/admin/alerts', label: 'Alertes', adminOnly: true },
  { to: '/admin/announcements', label: 'Annonces', adminOnly: true },
];
