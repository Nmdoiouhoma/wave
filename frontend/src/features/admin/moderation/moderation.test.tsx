import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { sessionStore } from '@/stores/session';
import { toastStore } from '@/stores/toast';
import { loginAs, renderRoute } from '@/test/utils';
import { ContentListPage } from './ContentListPage';
import { ReportQueuePage } from './ReportQueuePage';

const API = '*/api/v1';
const owner = { id: 'u1', username: 'kenji', displayName: 'Kenji', avatarUrl: null };
const target = {
  targetType: 'track',
  targetId: 't1',
  preview: { label: 'Titre signalé', imageUrl: null, owner },
  moderation: 'visible',
  suspended: null,
  openReportCount: 3,
  reasons: { spam: 2, hate: 1 },
  firstReportedAt: '2026-11-01T10:00:00Z',
  lastReportedAt: '2026-11-02T10:00:00Z',
};
const content = {
  type: 'track',
  id: 't1',
  label: 'Titre signalé',
  imageUrl: null,
  owner,
  moderation: 'visible',
  status: 'ready',
  visibility: 'public',
  openReportCount: 0,
  createdAt: '2026-11-01T10:00:00Z',
};

function setRoles(roles: ('ROLE_USER' | 'ROLE_MODERATOR' | 'ROLE_ADMIN')[]) {
  const user = sessionStore.getState().user!;
  sessionStore.getState().setUser({ ...user, roles });
}

beforeEach(async () => {
  await loginAs('lea@wave.local');
  toastStore.setState({ toasts: [] });
});

describe('report queue', () => {
  it('lists targets and sends a hide decision with the note after confirmation', async () => {
    const sent: unknown[] = [];
    server.use(
      http.get(`${API}/admin/report-targets`, () =>
        json({ items: sent.length ? [] : [target], nextCursor: null }),
      ),
      http.post(`${API}/admin/report-targets/track/t1/decision`, async ({ request }) => {
        sent.push(await request.json());
        return json({ ...target, moderation: 'hidden', openReportCount: 0 });
      }),
    );
    renderRoute(<ReportQueuePage />);
    expect(await screen.findByText('Titre signalé')).toBeInTheDocument();
    expect(screen.getByText('Spam ou arnaque · 2')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: 'Masquer' }));
    const dialog = screen.getByRole('dialog');
    await userEvent.type(within(dialog).getByLabelText(/Note/), 'spam avéré');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Masquer' }));
    await waitFor(() => expect(sent).toEqual([{ action: 'hide', note: 'spam avéré' }]));
    await waitFor(() => expect(screen.queryByText('Titre signalé')).not.toBeInTheDocument());
  });

  it('maps a 403 to a French message and rolls the optimistic removal back', async () => {
    server.use(
      http.get(`${API}/admin/report-targets`, () => json({ items: [target], nextCursor: null })),
      http.post(`${API}/admin/report-targets/track/t1/decision`, () => problem(403, 'forbidden')),
    );
    renderRoute(<ReportQueuePage />);
    await screen.findByText('Titre signalé');
    await userEvent.click(screen.getByRole('button', { name: 'Masquer' }));
    await userEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Masquer' }),
    );
    expect(await screen.findByText(/pas les droits/)).toBeInTheDocument();
    expect(screen.getAllByText('Titre signalé').length).toBeGreaterThan(0);
  });
});

describe('content list', () => {
  it('hides the delete button from moderators', async () => {
    setRoles(['ROLE_USER', 'ROLE_MODERATOR']);
    server.use(
      http.get(`${API}/admin/content`, () => json({ items: [content], nextCursor: null })),
    );
    renderRoute(<ContentListPage />);
    await screen.findByText('Titre signalé');
    expect(screen.queryByRole('button', { name: 'Supprimer' })).not.toBeInTheDocument();
  });

  it('requires a note and the typed word before deleting (admin)', async () => {
    setRoles(['ROLE_USER', 'ROLE_ADMIN']);
    const sent: unknown[] = [];
    server.use(
      http.get(`${API}/admin/content`, () =>
        json({ items: sent.length ? [] : [content], nextCursor: null }),
      ),
      http.post(`${API}/admin/content/track/t1/deletion`, async ({ request }) => {
        sent.push(await request.json());
        return new Response(null, { status: 204 });
      }),
    );
    renderRoute(<ContentListPage />);
    await screen.findByText('Titre signalé');
    await userEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
    const dialog = screen.getByRole('dialog');
    const confirm = within(dialog).getByRole('button', { name: 'Supprimer' });
    expect(confirm).toBeDisabled();
    await userEvent.type(within(dialog).getByLabelText(/Motif/), 'illégal');
    await userEvent.type(within(dialog).getByLabelText(/Tape SUPPRIMER/), 'SUPPRIMER');
    await userEvent.click(confirm);
    await waitFor(() => expect(sent).toEqual([{ note: 'illégal' }]));
    await waitFor(() => expect(screen.queryByText('Titre signalé')).not.toBeInTheDocument());
  });

  it('restores a hidden item through the dismiss decision', async () => {
    setRoles(['ROLE_USER', 'ROLE_MODERATOR']);
    const sent: unknown[] = [];
    server.use(
      http.get(`${API}/admin/content`, () =>
        json({ items: [{ ...content, moderation: 'hidden' }], nextCursor: null }),
      ),
      http.post(`${API}/admin/report-targets/track/t1/decision`, async ({ request }) => {
        sent.push(await request.json());
        return json(target);
      }),
    );
    renderRoute(<ContentListPage />);
    await screen.findByText('Titre signalé');
    await userEvent.click(screen.getByRole('button', { name: 'Rétablir' }));
    await userEvent.click(
      within(screen.getByRole('dialog')).getByRole('button', { name: 'Rétablir' }),
    );
    await waitFor(() => expect(sent).toEqual([{ action: 'dismiss', note: null }]));
  });
});
