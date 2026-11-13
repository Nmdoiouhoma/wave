// W-73: routes under /admin (children of the AdminLayout, which owns the staff guard).
// Each page is its own lazy chunk; the whole back-office is absent from the main bundle.
// W-74 / W-75: replace the `ComingSoon` elements / add routes here, and the menu entry in ./adminNav.ts.
import type { RouteObject } from 'react-router';
import { ComingSoon } from './ComingSoon';

export const adminRoute: RouteObject = {
  path: 'admin',
  lazy: async () => ({ Component: (await import('./AdminLayout')).AdminLayout }),
  children: [
    {
      index: true,
      lazy: async () => ({ Component: (await import('./DashboardPage')).DashboardPage }),
    },
    {
      path: 'users',
      lazy: async () => ({ Component: (await import('./UsersPage')).UsersPage }),
    },
    {
      path: 'users/:userId',
      lazy: async () => ({ Component: (await import('./UserDetailPage')).UserDetailPage }),
    },
    // W-74 slot
    {
      path: 'reports',
      lazy: async () => ({ Component: (await import('./moderation')).ReportQueuePage }),
    },
    {
      path: 'reports/:targetType/:targetId',
      lazy: async () => ({ Component: (await import('./moderation')).ReportTargetPage }),
    },
    {
      path: 'content',
      lazy: async () => ({ Component: (await import('./moderation')).ContentListPage }),
    },
    // W-75 (admin only: the API answers 403 for a moderator, the menu hides them)
    {
      path: 'users/:userId/activity',
      lazy: async () => ({ Component: (await import('./activity')).UserActivityPage }),
    },
    {
      path: 'audit-log',
      lazy: async () => ({ Component: (await import('./activity')).AuditLogPage }),
    },
    {
      path: 'alerts',
      lazy: async () => ({ Component: (await import('./alerts')).AlertsPage }),
    },
    {
      path: 'announcements',
      lazy: async () => ({ Component: (await import('./announcements/AnnouncementsPage')).AnnouncementsPage }),
    },
    { path: '*', element: <ComingSoon title="Page introuvable" /> },
  ],
};
