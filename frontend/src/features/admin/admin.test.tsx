import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { sessionStore } from '@/stores/session';
import { loginAs, renderRoute } from '@/test/utils';
import { AdminLayout } from './AdminLayout';
import type { AdminUserDetail } from './adminApi';
import { canSetRole, canSuspend } from './permissions';
import { DashboardPage } from './DashboardPage';
import { UserDetailPage } from './UserDetailPage';

const API = '*/api/v1';
const TARGET = '0192aaaa-0000-7000-8000-000000000009';

function detail(over: Partial<AdminUserDetail> = {}): AdminUserDetail {
  return {
    id: TARGET,
    username: 'target',
    displayName: 'Target',
    avatarUrl: null,
    email: 'target@wave.local',
    emailVerified: true,
    role: 'ROLE_USER',
    suspendedAt: null,
    createdAt: '2026-10-01T10:00:00+00:00',
    lastLoginAt: null,
    counts: { tracks: 1, posts: 2, comments: 3, playlists: 4, artists: 0, followers: 5, following: 6, reportsFiled: 0 },
    openReportCount: 0,
    openAlertCount: 0,
    lastSuspension: null,
    ...over,
  };
}

async function loginWithRoles(roles: ('ROLE_USER' | 'ROLE_MODERATOR' | 'ROLE_ADMIN')[]) {
  await loginAs('lea@wave.local');
  const user = sessionStore.getState().user!;
  sessionStore.getState().setUser({ ...user, roles });
}

describe('permissions (ADR 013 §2)', () => {
  const t = (role: 'ROLE_USER' | 'ROLE_MODERATOR' | 'ROLE_ADMIN') => ({ id: 't', role });
  it('moderator suspends plain users only, admin users and moderators, nobody an admin or self', () => {
    expect(canSuspend(['ROLE_MODERATOR'], 'me', t('ROLE_USER'))).toBe(true);
    expect(canSuspend(['ROLE_MODERATOR'], 'me', t('ROLE_MODERATOR'))).toBe(false);
    expect(canSuspend(['ROLE_ADMIN'], 'me', t('ROLE_MODERATOR'))).toBe(true);
    expect(canSuspend(['ROLE_ADMIN'], 'me', t('ROLE_ADMIN'))).toBe(false);
    expect(canSuspend(['ROLE_ADMIN'], 't', t('ROLE_USER'))).toBe(false);
  });
  it('only an admin changes a role, never self nor an admin', () => {
    expect(canSetRole(['ROLE_MODERATOR'], 'me', t('ROLE_USER'))).toBe(false);
    expect(canSetRole(['ROLE_ADMIN'], 'me', t('ROLE_USER'))).toBe(true);
    expect(canSetRole(['ROLE_ADMIN'], 'me', t('ROLE_ADMIN'))).toBe(false);
  });
});

describe('back-office shell', () => {
  beforeEach(() => sessionStore.getState().clearSession('expired'));

  it('redirects a plain user away from /admin', async () => {
    await loginWithRoles(['ROLE_USER']);
    renderRoute(<AdminLayout />, { path: '/admin', url: '/admin' });
    expect(await screen.findByText('autre page')).toBeInTheDocument();
  });

  it('shows the menu to a moderator, without admin-only entries', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    renderRoute(<AdminLayout />, { path: '/admin', url: '/admin' });
    expect(await screen.findByRole('link', { name: 'Utilisateurs' })).toBeInTheDocument();
  });
});

describe('dashboard', () => {
  it('hides alert and announcement cards for a moderator (null blocks)', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    server.use(http.get(`${API}/admin/dashboard/series`, () => problem(500, 'internal_error')));
    server.use(
      http.get(`${API}/admin/dashboard`, () =>
        json({
          users: { total: 10, new24h: 1, new7d: 2, suspended: 0, unverified: 3, moderators: 1, admins: 1 },
          moderation: { openReportTargets: 2, openReports: 4, hiddenContent24h: 0 },
          content: { tracks24h: 0, posts24h: 0, comments24h: 0, failedUploads24h: 0 },
          alerts: null,
          announcements: null,
          generatedAt: '2026-11-13T10:00:00+00:00',
        }),
      ),
    );
    renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByRole('region', { name: 'Modération' })).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Alertes' })).toBeNull();
  });

  it('shows "Accès réservé" on a 403', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    server.use(http.get(`${API}/admin/dashboard`, () => problem(403, 'forbidden')));
    renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByText('Accès réservé')).toBeInTheDocument();
  });
});

describe('dashboard series (W-83)', () => {
  const days7 = ['2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16'];
  const z = [0, 1, 0, 2, 0, 3, 1];
  const series = (staff: boolean) => ({
    range: '7d',
    timezone: 'Europe/Paris',
    days: days7,
    activity: staff ? { registrations: z, logins: z } : null,
    content: staff ? { tracks: z, posts: z, comments: z, rejectedUploads: z } : null,
    moderation: { reports: z, hidden: z },
    alerts: staff ? { high: z, medium: z, low: z } : null,
    generatedAt: '2026-11-16T10:00:00+00:00',
  });
  const stub = () =>
    server.use(
      http.get(`${API}/admin/dashboard`, () =>
        json({
          users: null,
          moderation: { openReportTargets: 0, openReports: 0, hiddenContent24h: 0 },
          content: null,
          alerts: null,
          announcements: null,
          generatedAt: '2026-11-16T10:00:00+00:00',
        }),
      ),
    );

  it('admin: four charts with accessible table, French day labels, range toggle', async () => {
    await loginWithRoles(['ROLE_ADMIN']);
    stub();
    const ranges: (string | null)[] = [];
    server.use(
      http.get(`${API}/admin/dashboard/series`, ({ request }) => {
        ranges.push(new URL(request.url).searchParams.get('range'));
        return json(series(true));
      }),
    );
    renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByRole('region', { name: 'Alertes par gravité' })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Contenus publiés' })).toBeInTheDocument();
    expect(screen.getAllByRole('table', { hidden: true })).toHaveLength(4);
    expect(screen.getAllByText(/mardi 10 novembre/i).length).toBeGreaterThan(0);
    await userEvent.click(screen.getByRole('button', { name: '30 j' }));
    await waitFor(() => expect(ranges).toContain('30d'));
  });

  it('moderator: moderation chart only', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    stub();
    server.use(http.get(`${API}/admin/dashboard/series`, () => json(series(false))));
    renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByRole('region', { name: 'Signalements et masquages' })).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Alertes par gravité' })).toBeNull();
    expect(screen.queryByRole('region', { name: 'Contenus publiés' })).toBeNull();
  });

  it('empty and error states', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    stub();
    const zero = { ...series(false), moderation: { reports: [0, 0, 0, 0, 0, 0, 0], hidden: [0, 0, 0, 0, 0, 0, 0] } };
    server.use(http.get(`${API}/admin/dashboard/series`, () => json(zero)));
    const { unmount } = renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByText('Aucune donnée sur la période.')).toBeInTheDocument();
    unmount();
    server.use(http.get(`${API}/admin/dashboard/series`, () => problem(500, 'internal_error')));
    renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByRole('button', { name: /réessayer/i })).toBeInTheDocument();
  });
});

describe('user detail', () => {
  it('moderator on a moderator: no action button, no email', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    server.use(
      http.get(`${API}/admin/users/${TARGET}`, () =>
        json(detail({ role: 'ROLE_MODERATOR', email: null, openAlertCount: null })),
      ),
    );
    renderRoute(<UserDetailPage />, { path: '/admin/users/:userId', url: `/admin/users/${TARGET}` });
    expect(await screen.findByRole('heading', { name: 'Target' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Suspendre' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Changer le rôle' })).toBeNull();
    expect(screen.queryByText('E-mail')).toBeNull();
  });

  it('suspension needs a note, then sends it and shows the new state', async () => {
    await loginWithRoles(['ROLE_ADMIN']);
    let state = detail();
    let body: unknown = null;
    server.use(
      http.get(`${API}/admin/users/${TARGET}`, () => json(state)),
      http.post(`${API}/admin/users/${TARGET}/suspension`, async ({ request }) => {
        body = await request.json();
        state = detail({ suspendedAt: '2026-11-13T10:00:00+00:00' });
        return json(state);
      }),
    );
    const user = userEvent.setup();
    renderRoute(<UserDetailPage />, { path: '/admin/users/:userId', url: `/admin/users/${TARGET}` });
    await user.click(await screen.findByRole('button', { name: 'Suspendre' }));
    const confirm = screen.getByRole('button', { name: 'Confirmer' });
    expect(confirm).toBeDisabled();
    await user.type(screen.getByLabelText(/Motif/), '  spam  ');
    expect(confirm).toBeEnabled();
    await user.click(confirm);
    await waitFor(() => expect(body).toEqual({ note: 'spam' }));
    expect(await screen.findByRole('button', { name: 'Réactiver le compte' })).toBeInTheDocument();
  });

  it('maps a 403 of the API inside the dialog', async () => {
    await loginWithRoles(['ROLE_ADMIN']);
    server.use(
      http.get(`${API}/admin/users/${TARGET}`, () => json(detail())),
      http.put(`${API}/admin/users/${TARGET}/role`, () => problem(403, 'forbidden')),
    );
    const user = userEvent.setup();
    renderRoute(<UserDetailPage />, { path: '/admin/users/:userId', url: `/admin/users/${TARGET}` });
    await user.click(await screen.findByRole('button', { name: 'Changer le rôle' }));
    await user.type(screen.getByLabelText(/Motif/), 'promotion');
    await user.click(screen.getByRole('button', { name: 'Confirmer' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Tu n’as pas le droit');
  });
});
