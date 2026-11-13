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
