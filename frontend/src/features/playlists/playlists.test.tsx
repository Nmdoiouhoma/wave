import { QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import type { ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import { api } from '@/api/instance';
import { createQueryClient } from '@/api/queryClient';
import { keys } from '@/api/queries/keys';
import { fetchAllPlaylistItems, useReorderPlaylist } from '@/api/queries/playlists';
import { Toaster } from '@/components/ui/Toaster';
import { db } from '@/mocks/db';
import { server } from '@/mocks/server';
import { PLAYLIST_IDS } from '@/mocks/socialFixtures';
import { TrackList } from '@/features/tracks/TrackList';
import { playerStore } from '@/stores/player';
import { loginAs, renderRoute } from '@/test/utils';
import { LibraryPage } from './LibraryPage';
import { PlaylistPage } from './PlaylistPage';
import { TrackSheets } from './TrackSheets';

const conflict = () =>
  HttpResponse.json(
    {
      type: 'urn:wave:problem:playlist_order_mismatch',
      title: 'x',
      status: 409,
      code: 'playlist_order_mismatch',
    },
    { status: 409, headers: { 'Content-Type': 'application/problem+json' } },
  );

describe('library (W-24)', () => {
  it('creates a playlist and opens it', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    const { router } = renderRoute(<LibraryPage />, { path: '/library' });
    expect(await screen.findByRole('list', { name: 'Mes playlists' })).toHaveTextContent(
      'Matins calmes',
    );
    await user.click(screen.getByRole('button', { name: 'Nouvelle playlist' }));
    const dialog = screen.getByRole('dialog', { name: 'Nouvelle playlist' });
    await user.type(within(dialog).getByLabelText('Nom de la playlist'), 'Soirée');
    await user.click(within(dialog).getByRole('switch', { name: 'Playlist publique' }));
    await user.click(within(dialog).getByRole('button', { name: 'Créer la playlist' }));
    await waitFor(() => expect(router.state.location.pathname).toMatch(/^\/p\//));
    expect(db.state.playlists.find((p) => p.name === 'Soirée')).toMatchObject({ isPublic: false });
  });

  it('shows liked tracks and liked playlists tabs', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<LibraryPage />, { path: '/library' });
    await user.click(await screen.findByRole('tab', { name: 'Titres likés' }));
    expect(await screen.findByRole('list', { name: 'Titres likés' })).toHaveTextContent(
      'Vinyle 33',
    );
    await user.click(screen.getByRole('tab', { name: 'Playlists likées' }));
    expect(await screen.findByRole('list', { name: 'Playlists likées' })).toHaveTextContent(
      'Lo-fi pour bosser',
    );
  });
});

describe('playlist page (W-24)', () => {
  it('plays the whole playlist in order, skipping unavailable tracks', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<PlaylistPage />, { path: '/p/:id', url: `/p/${PLAYLIST_IDS.matins}` });
    await screen.findByText('Brume sur le port');
    await user.click(screen.getByRole('button', { name: 'Lecture' }));
    expect(playerStore.getState().queue.map((t) => t.title)).toEqual([
      'Brume sur le port',
      'Café crème',
      'Marée basse',
      'Pluie sur Croix-Rousse',
    ]);
    playerStore.getState().stop();
  });

  it('owner sees drag handles and unavailable items', async () => {
    await loginAs('lea@wave.local');
    renderRoute(<PlaylistPage />, { path: '/p/:id', url: `/p/${PLAYLIST_IDS.brouillons}` });
    expect(await screen.findByText('Morceau indisponible')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: /^Déplacer / })).toHaveLength(2);
    expect(screen.getByRole('button', { name: 'Lecture' })).toBeEnabled();
  });

  it('visitors see neither handles nor edit, but can like the playlist', async () => {
    await loginAs('kenji@wave.local');
    renderRoute(<PlaylistPage />, { path: '/p/:id', url: `/p/${PLAYLIST_IDS.matins}` });
    await screen.findByText('Brume sur le port');
    expect(screen.queryByRole('button', { name: /^Déplacer / })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Modifier' })).toBeNull();
    expect(
      screen.getByRole('button', { name: /Ajouter « Matins calmes » à tes coups de cœur/ }),
    ).toBeInTheDocument();
  });

  it('private playlist of someone else → not found', async () => {
    await loginAs('kenji@wave.local');
    renderRoute(<PlaylistPage />, { path: '/p/:id', url: `/p/${PLAYLIST_IDS.brouillons}` });
    expect(await screen.findByText('Introuvable.')).toBeInTheDocument();
  });
});

describe('reorder (optimistic, full order, rollback on 409)', () => {
  function setup() {
    const qc = createQueryClient();
    const wrapper = ({ children }: { children: ReactNode }) => (
      <QueryClientProvider client={qc}>{children}</QueryClientProvider>
    );
    return { qc, ...renderHook(() => useReorderPlaylist(PLAYLIST_IDS.brouillons), { wrapper }) };
  }

  it('sends every trackId (unavailable included) and keeps the new order', async () => {
    await loginAs('lea@wave.local');
    const { qc, result } = setup();
    const items = await fetchAllPlaylistItems(PLAYLIST_IDS.brouillons);
    qc.setQueryData(keys.playlistItems(PLAYLIST_IDS.brouillons), items);
    let sent: string[] = [];
    server.events.on('request:start', async ({ request }) => {
      if (request.url.endsWith('/order'))
        sent = ((await request.clone().json()) as { trackIds: string[] }).trackIds;
    });
    const reversed = [...items].reverse();
    await act(() => result.current.mutateAsync(reversed));
    expect(sent).toEqual(reversed.map((i) => i.trackId));
    expect(sent).toHaveLength(2);
    expect(db.state.playlistItems[PLAYLIST_IDS.brouillons]!.map((i) => i.trackId)).toEqual(sent);
  });

  it('applies the order immediately and rolls back on 409', async () => {
    await loginAs('lea@wave.local');
    server.use(
      http.put('*/api/v1/playlists/:id/order', async () => {
        await new Promise((r) => setTimeout(r, 300));
        return conflict();
      }),
    );
    const { qc, result } = setup();
    const items = await fetchAllPlaylistItems(PLAYLIST_IDS.brouillons);
    qc.setQueryData(keys.playlistItems(PLAYLIST_IDS.brouillons), items);
    const reversed = [...items].reverse();
    act(() => result.current.mutate(reversed));
    await waitFor(() =>
      expect(
        qc
          .getQueryData<typeof items>(keys.playlistItems(PLAYLIST_IDS.brouillons))!
          .map((i) => i.trackId),
      ).toEqual(reversed.map((i) => i.trackId)),
    );
    await waitFor(() => expect(result.current.isError).toBe(true));
    expect(
      qc
        .getQueryData<typeof items>(keys.playlistItems(PLAYLIST_IDS.brouillons))!
        .map((i) => i.trackId),
    ).toEqual(items.map((i) => i.trackId));
  });
});

describe('add to playlist from a track menu (W-24)', () => {
  it('adds, and explains a duplicate', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    const track = await api.tracks.get('0192f3a2-0000-7000-8000-000000000002');
    renderRoute(
      <>
        <TrackList label="Titres" tracks={[track]} />
        <TrackSheets />
        <Toaster />
      </>,
    );
    await user.click(screen.getByRole('button', { name: 'Plus d’actions pour Marée basse' }));
    await user.click(screen.getByRole('button', { name: 'Ajouter à une playlist' }));
    const dialog = screen.getByRole('dialog', { name: 'Ajouter à une playlist' });
    await user.click(await within(dialog).findByRole('button', { name: /Matins calmes/ }));
    expect(await screen.findByText('Ce morceau est déjà dans cette playlist.')).toBeInTheDocument();
    await user.click(within(dialog).getByRole('button', { name: /Brouillons/ }));
    expect(await screen.findByText('Ajouté à « Brouillons »')).toBeInTheDocument();
    expect(db.state.playlistItems[PLAYLIST_IDS.brouillons]!.map((i) => i.trackId)).toContain(
      track.id,
    );
  });
});
