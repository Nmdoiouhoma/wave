import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { db } from '@/mocks/db';
import { server } from '@/mocks/server';
import { PLAYLIST_IDS } from '@/mocks/socialFixtures';
import { loginAs, renderRoute } from '@/test/utils';
import { PlaylistPage } from './PlaylistPage';

const T = (n: string) => `0192f3a2-0000-7000-8000-0000000000${n}`;
const problem = (status: number, code: string, headers: Record<string, string> = {}) =>
  HttpResponse.json(
    { type: `urn:wave:problem:${code}`, title: 'x', status, code },
    { status, headers: { 'Content-Type': 'application/problem+json', ...headers } },
  );

async function openSheet(playlistId: string = PLAYLIST_IDS.matins) {
  await loginAs('lea@wave.local');
  const user = userEvent.setup();
  renderRoute(
    <>
      <PlaylistPage />
      <Toaster />
    </>,
    { path: '/p/:id', url: `/p/${playlistId}` },
  );
  await user.click(await screen.findByRole('button', { name: 'Ajouter des morceaux' }));
  const dialog = screen.getByRole('dialog', { name: 'Ajouter des morceaux' });
  return { user, dialog };
}

const row = (scope: HTMLElement, title: string) => within(scope).getByText(title).closest('li')!;

describe('add tracks from the playlist page (W-29b)', () => {
  it('visitors do not get the button', async () => {
    await loginAs('kenji@wave.local');
    renderRoute(<PlaylistPage />, { path: '/p/:id', url: `/p/${PLAYLIST_IDS.matins}` });
    await screen.findByText('Brume sur le port');
    expect(screen.queryByRole('button', { name: 'Ajouter des morceaux' })).toBeNull();
  });

  it('below 2 chars: own ready tracks then liked tracks, already added ones marked', async () => {
    const { dialog } = await openSheet();
    const own = within(dialog).getByRole('region', { name: 'Tes morceaux' });
    await within(own).findByText('Néons');
    expect(within(row(own, 'Brume sur le port')).getByText('Ajouté')).toBeInTheDocument();
    expect(
      within(row(own, 'Néons')).getByRole('button', { name: 'Ajouter Néons' }),
    ).toBeInTheDocument();
    expect(within(own).getByText('Démo privée')).toBeInTheDocument(); // own private ready
    expect(within(own).queryByText('Session du jeudi')).toBeNull(); // failed
    expect(within(own).queryByText('Aube (en cours)')).toBeNull(); // processing

    const liked = within(dialog).getByRole('region', { name: 'Tes titres likés' });
    await within(liked).findByText('Vinyle 33');
    expect(within(row(liked, 'Café crème')).getByText('Ajouté')).toBeInTheDocument();
  });

  it('searches tracks from 2 chars, adds one and refreshes the count', async () => {
    let lastSearch: URL | null = null;
    server.events.on('request:start', ({ request }) => {
      if (request.url.includes('/search')) lastSearch = new URL(request.url);
    });
    const { user, dialog } = await openSheet();
    expect(screen.getByText(/4 morceaux/)).toBeInTheDocument();
    await user.type(within(dialog).getByLabelText('Rechercher un morceau'), 'vin');
    const results = await within(dialog).findByRole('region', { name: 'Résultats pour « vin »' });
    await user.click(await within(results).findByRole('button', { name: 'Ajouter Vinyle 33' }));
    expect(await within(row(results, 'Vinyle 33')).findByText('Ajouté')).toBeInTheDocument();
    expect(lastSearch!.searchParams.get('type')).toBe('tracks');
    expect(lastSearch!.searchParams.get('q')).toBe('vin');
    expect(db.state.playlistItems[PLAYLIST_IDS.matins]!.map((i) => i.trackId)).toContain(T('09'));
    expect(await screen.findByText(/5 morceaux/)).toBeInTheDocument();
    server.events.removeAllListeners();
  });

  it("never lists someone else's private track", async () => {
    const { user, dialog } = await openSheet();
    await user.type(within(dialog).getByLabelText('Rechercher un morceau'), 'brouillon');
    expect(
      await within(dialog).findByText('Aucun morceau ne correspond à « brouillon ».'),
    ).toBeInTheDocument();
  });

  it('a 409 duplicate shows "Ajouté"; playlist_full is explained in French', async () => {
    server.use(
      http.post('*/playlists/:id/items', async ({ request }) => {
        const { trackId } = (await request.json()) as { trackId: string };
        return trackId === T('03')
          ? problem(409, 'track_already_in_playlist')
          : problem(409, 'playlist_full');
      }),
    );
    const { user, dialog } = await openSheet();
    const own = within(dialog).getByRole('region', { name: 'Tes morceaux' });
    await user.click(await within(own).findByRole('button', { name: 'Ajouter Néons' }));
    expect(await within(row(own, 'Néons')).findByText('Ajouté')).toBeInTheDocument();
    await user.click(within(own).getByRole('button', { name: 'Ajouter Démo privée' }));
    expect(
      await screen.findByText('Cette playlist est pleine (500 morceaux maximum).'),
    ).toBeInTheDocument();
  });

  it('explains a 429 on search with the retry hint', async () => {
    server.use(http.get('*/search', () => problem(429, 'rate_limited', { 'Retry-After': '30' })));
    const { user, dialog } = await openSheet();
    await user.type(within(dialog).getByLabelText('Rechercher un morceau'), 'vin');
    await waitFor(() =>
      expect(within(dialog).getByText(/Réessaie dans 30 s\./)).toBeInTheDocument(),
    );
  });
});
