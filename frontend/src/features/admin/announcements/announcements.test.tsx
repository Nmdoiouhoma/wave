import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { toastStore } from '@/stores/toast';
import { loginAs, renderRoute } from '@/test/utils';
import { AlertsPage } from '../alerts';
import { AnnouncementBanner } from './AnnouncementBanner';
import { AnnouncementsPage } from './AnnouncementsPage';

const API = '*/api/v1';
const ann = {
  id: 'a1',
  title: 'Maintenance',
  body: '<b>ce soir</b>',
  level: 'warning',
  startsAt: '2026-11-13T10:00:00Z',
  endsAt: null,
  updatedAt: '2026-11-13T10:00:00Z',
};

beforeEach(async () => {
  localStorage.clear();
  await loginAs('lea@wave.local');
  toastStore.setState({ toasts: [] });
});

describe('announcement banner', () => {
  it('renders HTML as text and remembers the dismissal per id + updatedAt', async () => {
    server.use(http.get(`${API}/announcements`, () => json({ items: [ann] })));
    const { unmount } = renderRoute(<AnnouncementBanner />);
    expect(await screen.findByText(/<b>ce soir<\/b>/)).toBeInTheDocument();
    expect(document.querySelector('b')).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: /Fermer/ }));
    expect(screen.queryByText('Maintenance')).not.toBeInTheDocument();
    unmount();
    renderRoute(<AnnouncementBanner />);
    await waitFor(() => expect(screen.queryByText('Maintenance')).not.toBeInTheDocument());
  });

  it('shows again when the announcement was updated', async () => {
    localStorage.setItem('wave.announcements.dismissed', JSON.stringify(['a1:2026-11-12T00:00:00Z']));
    server.use(http.get(`${API}/announcements`, () => json({ items: [ann] })));
    renderRoute(<AnnouncementBanner />);
    expect(await screen.findByText('Maintenance')).toBeInTheDocument();
  });
});

describe('admin pages', () => {
  it('creates an announcement', async () => {
    const sent: unknown[] = [];
    server.use(
      http.get(`${API}/admin/announcements`, () => json({ items: [], nextCursor: null })),
      http.post(`${API}/admin/announcements`, async ({ request }) => {
        sent.push(await request.json());
        return json(ann, 201);
      }),
    );
    renderRoute(<AnnouncementsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Nouvelle annonce' }));
    const d = screen.getByRole('dialog');
    await userEvent.type(within(d).getByLabelText('Titre'), 'Salut');
    await userEvent.type(within(d).getByLabelText('Texte'), 'Bonjour');
    await userEvent.click(within(d).getByRole('button', { name: 'Publier' }));
    await waitFor(() =>
      expect(sent).toEqual([
        { title: 'Salut', body: 'Bonjour', level: 'info', startsAt: null, endsAt: null },
      ]),
    );
  });

  it('acknowledges an alert with a note and maps already_acknowledged', async () => {
    const alert = {
      id: 'al1',
      rule: 'login_failures',
      severity: 'high',
      subjectUser: null,
      subjectIpHash: 'ab',
      subjectTarget: null,
      hitCount: 4,
      firstSeenAt: '2026-11-13T10:00:00Z',
      lastSeenAt: '2026-11-13T10:05:00Z',
      status: 'open',
      acknowledgedBy: null,
      acknowledgedAt: null,
      note: null,
    };
    server.use(
      http.get(`${API}/admin/alerts`, () => json({ items: [alert], nextCursor: null })),
      http.post(`${API}/admin/alerts/al1/acknowledgement`, () =>
        problem(409, 'already_acknowledged'),
      ),
    );
    renderRoute(<AlertsPage />);
    await userEvent.click(await screen.findByRole('button', { name: 'Acquitter' }));
    const d = screen.getByRole('dialog');
    await userEvent.type(within(d).getByLabelText(/Note/), 'vu');
    await userEvent.click(within(d).getByRole('button', { name: 'Acquitter' }));
    expect(await within(d).findByText(/déjà été acquittée/)).toBeInTheDocument();
  });
});
