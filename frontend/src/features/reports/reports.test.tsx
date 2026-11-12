import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import type { CreateReportRequest } from '@/api/types';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { toastStore } from '@/stores/toast';
import { loginAs, renderRoute } from '@/test/utils';
import { PostMenuSheet } from '@/features/feed/PostSheets';
import { makePost } from '@/features/feed/testPosts';
import { ReportButton } from './ReportButton';
import { REPORT_REASONS } from './reportOptions';

const API = '*/api/v1';
const TARGET = { type: 'track', id: '0192cccc-0000-7000-8000-000000000001' } as const;
const toasts = () => toastStore.getState().toasts.map((t) => t.message);

function serveReport(reply: () => Response) {
  const sent: CreateReportRequest[] = [];
  server.use(
    http.post(`${API}/reports`, async ({ request }) => {
      sent.push((await request.json()) as CreateReportRequest);
      return reply();
    }),
  );
  return sent;
}

async function report(reason: string, details?: string) {
  await userEvent.click(screen.getByRole('button', { name: 'Signaler ce morceau' }));
  const dialog = await screen.findByRole('dialog', { name: 'Signaler ce morceau' });
  await userEvent.click(within(dialog).getByLabelText(reason));
  if (details) await userEvent.type(within(dialog).getByLabelText(/Précisions/), details);
  await userEvent.click(within(dialog).getByRole('button', { name: 'Envoyer le signalement' }));
  return dialog;
}

beforeEach(async () => {
  toastStore.setState({ toasts: [] });
  await loginAs('lea@wave.local');
});

describe('report dialog (W-36)', () => {
  it('offers the 8 reasons in French and sends reason + trimmed details', async () => {
    const sent = serveReport(() =>
      json({ id: 'r1', status: 'open', createdAt: '2026-11-12T10:00:00Z' }, 201),
    );
    renderRoute(<ReportButton target={TARGET} />);
    await userEvent.click(screen.getByRole('button', { name: 'Signaler ce morceau' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getAllByRole('radio')).toHaveLength(8);
    expect(REPORT_REASONS.map((r) => r.value)).toEqual([
      'spam',
      'harassment',
      'hate',
      'sexual',
      'violence',
      'copyright',
      'impersonation',
      'other',
    ]);
    expect(within(dialog).getByRole('button', { name: 'Envoyer le signalement' })).toBeDisabled();
    await userEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));

    await report('Spam ou arnaque', '  copie de mon son  ');
    await waitFor(() => expect(toasts()).toContain('Merci, on regarde'));
    expect(sent).toEqual([
      { targetType: 'track', targetId: TARGET.id, reason: 'spam', details: 'copie de mon son' },
    ]);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('sends null details when empty', async () => {
    const sent = serveReport(() =>
      json({ id: 'r1', status: 'open', createdAt: '2026-11-12T10:00:00Z' }, 201),
    );
    renderRoute(<ReportButton target={TARGET} />);
    await report('Autre');
    await waitFor(() => expect(sent[0]?.details).toBeNull());
  });

  it.each([
    [409, 'already_reported', 'Tu as déjà signalé ce contenu'],
    [404, 'not_found', 'Ce contenu n’est plus disponible'],
  ] as const)('%i closes with "%s"', async (status, code, message) => {
    serveReport(() => problem(status, code));
    renderRoute(<ReportButton target={TARGET} />);
    await report('Discours haineux');
    await waitFor(() => expect(toasts()).toContain(message));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('shows the wait on 429 and keeps the form open', async () => {
    serveReport(() => problem(429, 'rate_limited', { headers: { 'Retry-After': '120' } }));
    renderRoute(<ReportButton target={TARGET} />);
    const dialog = await report('Violence ou menaces');
    expect(await within(dialog).findByText(/Réessaie dans 2 min\./)).toBeInTheDocument();
  });

  it('blocks details over 1000 characters', async () => {
    renderRoute(<ReportButton target={TARGET} />);
    await userEvent.click(screen.getByRole('button', { name: 'Signaler ce morceau' }));
    const dialog = await screen.findByRole('dialog');
    const area = within(dialog).getByLabelText(/Précisions/);
    expect(area).toHaveAttribute('maxLength', '1000');
  });

  it('hides "Signaler ce post" on an own post in the feed menu', async () => {
    const { unmount } = renderRoute(
      <PostMenuSheet post={makePost(1, { isOwner: true })} open onClose={() => {}} />,
    );
    expect(await screen.findByRole('dialog', { name: 'Options' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Signaler ce post/ })).toBeNull();
    unmount();
    renderRoute(<PostMenuSheet post={makePost(1)} open onClose={() => {}} />);
    await userEvent.click(await screen.findByRole('button', { name: /Signaler ce post/ }));
    expect(await screen.findByRole('dialog', { name: 'Signaler ce post' })).toBeInTheDocument();
  });
});
