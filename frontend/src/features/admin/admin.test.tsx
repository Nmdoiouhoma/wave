import { screen, waitFor, within } from '@testing-library/react';
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
    counts: {
      tracks: 1,
      posts: 2,
      comments: 3,
      playlists: 4,
      artists: 0,
      followers: 5,
      following: 6,
      reportsFiled: 0,
    },
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
          users: {
            total: 10,
            new24h: 1,
            new7d: 2,
            suspended: 0,
            unverified: 3,
            moderators: 1,
            admins: 1,
          },
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
  const days7 = [
    '2026-11-10',
    '2026-11-11',
    '2026-11-12',
    '2026-11-13',
    '2026-11-14',
    '2026-11-15',
    '2026-11-16',
  ];
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
    expect(
      await screen.findByRole('region', { name: 'Signalements et masquages' }),
    ).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Alertes par gravité' })).toBeNull();
    expect(screen.queryByRole('region', { name: 'Contenus publiés' })).toBeNull();
  });

  it('empty and error states', async () => {
    await loginWithRoles(['ROLE_MODERATOR']);
    stub();
    const zero = {
      ...series(false),
      moderation: { reports: [0, 0, 0, 0, 0, 0, 0], hidden: [0, 0, 0, 0, 0, 0, 0] },
    };
    server.use(http.get(`${API}/admin/dashboard/series`, () => json(zero)));
    const { unmount } = renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByText('Aucune donnée sur la période.')).toBeInTheDocument();
    unmount();
    server.use(http.get(`${API}/admin/dashboard/series`, () => problem(500, 'internal_error')));
    renderRoute(<DashboardPage />, { path: '/admin' });
    expect(await screen.findByRole('button', { name: /réessayer/i })).toBeInTheDocument();
  });
});

describe('supervision panel (W-89)', () => {
  type Level = 'ok' | 'attention' | 'alerte' | 'critique';
  const dashboard = (staff: boolean) => ({
    users: null,
    moderation: { openReportTargets: 0, openReports: 0, hiddenContent24h: 0 },
    content: null,
    alerts: staff ? { open: 3, openHigh: 1, openMedium: 2, openLow: 0 } : null,
    announcements: null,
    generatedAt: '2026-11-16T13:30:00+00:00',
  });
  // 24 buckets from 2026-11-15T14:00Z; the last one starts at 13:00Z = 14 h in Paris.
  const hourly = (count: (i: number) => number, level: (i: number) => Level) =>
    Array.from({ length: 24 }, (_, i) => ({
      hourStart: new Date(Date.UTC(2026, 10, 15, 14 + i)).toISOString(),
      count: count(i),
      level: level(i),
    }));
  const state = (global: Level, zero = false) => ({
    global,
    signals: [
      {
        key: 'suspicious',
        level: global,
        value: zero ? 0 : 3,
        measured: true,
        thresholds: null,
        severities: zero ? { high: 0, medium: 0, low: 0 } : { high: 1, medium: 2, low: 0 },
        detail: zero ? 'aucune alerte ouverte' : '3 alertes ouvertes',
      },
      {
        key: 'logins',
        level: 'ok',
        value: zero ? 0 : 0.12,
        measured: !zero,
        severities: null,
        thresholds: [0.2, 0.5, 0.8],
        detail: zero ? 'trop peu de tentatives (4 sur 1 h)' : '12 % d\u2019échecs sur 1 h',
      },
      {
        key: 'abuse',
        level: 'ok',
        value: 0,
        measured: true,
        severities: null,
        thresholds: [1, 10, 50],
        detail: 'aucune limite atteinte',
      },
      {
        key: 'uploads',
        level: 'ok',
        value: 0,
        measured: true,
        severities: null,
        thresholds: [1, 10, 50],
        detail: 'aucun envoi refusé',
      },
    ],
    hourly: hourly(
      (i) => (zero ? 0 : i),
      (i) => (zero || i < 23 ? 'ok' : global),
    ),
    updatedAt: '2026-11-16T13:30:00+00:00',
  });
  const alertItem = {
    id: '0192aaaa-0000-7000-8000-0000000000a1',
    rule: 'token_reuse',
    severity: 'high',
    subjectUser: null,
    subjectIpHash: null,
    subjectTarget: null,
    hitCount: 2,
    firstSeenAt: '2026-11-16T12:00:00+00:00',
    lastSeenAt: '2026-11-16T13:00:00+00:00',
    status: 'open',
    acknowledgedBy: null,
    acknowledgedAt: null,
    note: null,
  };
  const setup = async (
    staff: boolean,
    supervision: () => Response,
    items: unknown[] = [alertItem],
  ) => {
    await loginWithRoles([staff ? 'ROLE_ADMIN' : 'ROLE_MODERATOR']);
    server.use(
      http.get(`${API}/admin/dashboard`, () => json(dashboard(staff))),
      http.get(`${API}/admin/dashboard/series`, () => problem(500, 'internal_error')),
      http.get(`${API}/admin/dashboard/supervision`, supervision),
      http.get(`${API}/admin/alerts`, () => json({ items, nextCursor: null })),
    );
    return renderRoute(<DashboardPage />, { path: '/admin' });
  };

  it('admin: banner, four tiles, 24 bars, table fallback, open alerts', async () => {
    await setup(true, () => json(state('alerte')));
    const panel = await screen.findByRole('region', { name: 'Supervision' });
    expect(await within(panel).findByText('État global : Alerte')).toBeInTheDocument();
    for (const name of ['Activité suspecte', 'Connexions', 'Abus et limites', 'Envois de fichiers'])
      expect(within(panel).getByRole('region', { name })).toBeInTheDocument();
    expect(within(panel).getByText('12 %')).toBeInTheDocument();
    expect(within(panel).getByText('3 alertes ouvertes')).toBeInTheDocument();
    const suspicious = within(panel).getByRole('region', { name: 'Activité suspecte' });
    expect(
      within(suspicious)
        .getAllByRole('listitem')
        .map((li) => li.textContent),
    ).toEqual([
      expect.stringMatching(/1$/),
      expect.stringMatching(/2$/),
      expect.stringMatching(/0$/),
    ]);
    const gauge = within(panel)
      .getByRole('region', { name: 'Connexions' })
      .querySelectorAll('span.w-px');
    expect([...gauge].map((t) => (t as HTMLElement).style.left)).toEqual(['20%', '50%', '80%']);
    expect(within(panel).getAllByTestId('supervision-bar')).toHaveLength(24);
    const table = within(panel).getByRole('table', { hidden: true });
    expect(within(table).getAllByRole('row', { hidden: true })).toHaveLength(25);
    expect(within(table).getByText(/lundi 16 novembre.*14\sh/)).toBeInTheDocument();
    const legend = within(panel).getByRole('list', { name: 'Légende des niveaux' });
    expect(within(legend).getAllByRole('listitem')).toHaveLength(4);
    expect(await within(panel).findByText('Réutilisation de jeton')).toBeInTheDocument();
  });

  it('hover on a bar shows its tooltip', async () => {
    await setup(true, () => json(state('alerte')));
    const bars = await screen.findAllByTestId('supervision-bar');
    await userEvent.hover(bars[23]!);
    expect(await screen.findByText(/14\sh · 23 événements · Alerte/)).toBeInTheDocument();
  });

  it('moderator: panel absent, whether by the dashboard split or by the null shape', async () => {
    let calls = 0;
    const hidden = () => {
      calls++;
      return json({
        global: null,
        signals: [],
        hourly: null,
        updatedAt: '2026-11-16T13:30:00+00:00',
      });
    };
    const { unmount } = await setup(false, hidden);
    expect(await screen.findByRole('region', { name: 'Modération' })).toBeInTheDocument();
    expect(screen.queryByRole('region', { name: 'Supervision' })).toBeNull();
    expect(calls).toBe(0);
    unmount();
    await setup(true, hidden);
    await waitFor(() => expect(calls).toBe(1));
    await waitFor(() => expect(screen.queryByRole('region', { name: 'Supervision' })).toBeNull());
  });

  it('critical level: icon and label, never colour alone', async () => {
    await setup(true, () => json(state('critique')));
    const label = await screen.findByText('État global : Critique');
    expect(label).toHaveAttribute('data-level', 'critique');
    expect(label.querySelector('svg')).not.toBeNull();
    const tile = screen.getByRole('region', { name: 'Activité suspecte' });
    const badge = within(tile).getByText('Critique');
    expect(badge.querySelector('svg')).not.toBeNull();
  });

  it('zero state', async () => {
    await setup(true, () => json(state('ok', true)), []);
    expect(await screen.findByText('État global : Normal')).toBeInTheDocument();
    const logins = screen.getByRole('region', { name: 'Connexions' });
    expect(within(logins).getAllByText(/trop peu de connexions pour évaluer/i).length).toBeGreaterThan(0);
    expect(within(logins).queryByText('0 %')).toBeNull();
    expect(logins.querySelector('span.w-px')).toBeNull();
    expect(
      screen.getByText('Aucun événement notable sur les 24 dernières heures.'),
    ).toBeInTheDocument();
    expect(screen.queryAllByTestId('supervision-bar')).toHaveLength(0);
    expect(await screen.findByText('Aucune alerte ouverte.')).toBeInTheDocument();
  });

  it('error state', async () => {
    await setup(true, () => problem(500, 'internal_error'));
    const panel = await screen.findByRole('region', { name: 'Supervision' });
    expect(await within(panel).findByRole('button', { name: /réessayer/i })).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Modération' })).toBeInTheDocument();
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
    renderRoute(<UserDetailPage />, {
      path: '/admin/users/:userId',
      url: `/admin/users/${TARGET}`,
    });
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
    renderRoute(<UserDetailPage />, {
      path: '/admin/users/:userId',
      url: `/admin/users/${TARGET}`,
    });
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
    renderRoute(<UserDetailPage />, {
      path: '/admin/users/:userId',
      url: `/admin/users/${TARGET}`,
    });
    await user.click(await screen.findByRole('button', { name: 'Changer le rôle' }));
    await user.type(screen.getByLabelText(/Motif/), 'promotion');
    await user.click(screen.getByRole('button', { name: 'Confirmer' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Tu n’as pas le droit');
  });
});
