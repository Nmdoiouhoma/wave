import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { mockConfig } from '@/mocks/db';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { playerStore } from '@/stores/player';
import { FakeAudio } from '@/test/fakeAudio';
import { loginAs, renderRoute } from '@/test/utils';
import { SearchPage } from '@/features/search/SearchPage';
import { CatalogArtistPage } from './CatalogArtistPage';
import { setPreviewAudioFactory, type PreviewAudio } from './previewPlayer';

const API = '*/api/v1';
let audios: FakeAudio[] = [];

beforeEach(() => {
  audios = [];
  setPreviewAudioFactory(() => {
    const a = new FakeAudio();
    audios.push(a);
    return a as unknown as PreviewAudio;
  });
});
afterEach(() => {
  setPreviewAudioFactory(null);
  mockConfig.catalog = false;
  vi.restoreAllMocks();
});

describe('catalogue off', () => {
  it('shows no catalogue surface and never calls /catalog', async () => {
    await loginAs('lea@wave.local');
    const hits = vi.fn();
    server.use(http.get(`${API}/catalog/*`, () => (hits(), problem(404, 'not_found'))));
    renderRoute(<SearchPage />, { path: '/discover' });
    await screen.findByRole('heading', { name: 'Découvrir' });
    expect(screen.queryByText('Artistes célèbres')).toBeNull();
    expect(hits).not.toHaveBeenCalled();
  });
});

describe('catalogue on', () => {
  beforeEach(async () => {
    mockConfig.catalog = true;
    await loginAs('lea@wave.local');
  });

  it('lists famous artists with attribution and a safe Deezer link', async () => {
    renderRoute(<SearchPage />, { path: '/discover' });
    expect(await screen.findByText('Daft Punk')).toBeInTheDocument();
    expect(screen.getByText('Données et extraits : Deezer')).toBeInTheDocument();
    const link = screen.getAllByRole('link', { name: 'Écouter sur Deezer' })[0]!;
    expect(link).toHaveAttribute('rel', 'noopener noreferrer');
  });

  it('omits the section when available is false', async () => {
    server.use(
      http.get(`${API}/catalog/charts/artists`, () =>
        json({
          available: false,
          items: [],
          attribution: { text: 'Données et extraits : Deezer', url: 'https://www.deezer.com' },
        }),
      ),
    );
    renderRoute(<SearchPage />, { path: '/discover' });
    await screen.findByRole('heading', { name: 'Découvrir' });
    // Wait for the response instead of a fixed delay (flaky under load: the section shows while loading).
    await waitFor(() => expect(screen.queryByText('Artistes célèbres')).toBeNull());
  });

  it('puts catalogue results in a separate block under the search', async () => {
    renderRoute(<SearchPage />, { path: '/discover', url: '/discover?q=daft' });
    expect(
      await screen.findByRole('region', { name: 'Artistes du catalogue' }),
    ).toBeInTheDocument();
    expect(await screen.findByText('Daft Punk')).toBeInTheDocument();
  });

  it('plays a preview without crossOrigin or Wave listen, and refetches once on media error', async () => {
    const wavePause = vi.spyOn(playerStore.getState(), 'pause');
    let artistFetches = 0;
    server.use(
      http.post(`${API}/*`, () => {
        throw new Error('no write call expected');
      }),
    );
    server.events.on('request:start', ({ request }) => {
      if (request.url.includes('/catalog/artists/dz')) artistFetches++;
    });
    renderRoute(<CatalogArtistPage />, {
      path: '/discover/artists/:externalId',
      url: '/discover/artists/dz:27',
    });
    await screen.findByRole('heading', { name: 'Daft Punk' });
    expect(screen.getByText('Données et extraits : Deezer')).toBeInTheDocument();
    await userEvent.click(
      screen.getByRole('button', { name: /Écouter l’extrait de Daft Punk titre 1/ }),
    );
    expect(audios).toHaveLength(1);
    expect(audios[0]!.src).toContain('cdnt-preview.dzcdn.net');
    expect((audios[0] as unknown as { crossOrigin?: string }).crossOrigin).toBeUndefined();
    expect(wavePause).toHaveBeenCalled();
    const before = artistFetches;
    act(() => audios[0]!.emit('error'));
    await waitFor(() => expect(artistFetches).toBe(before + 1));
    act(() => audios[0]!.emit('error'));
    await screen.findByText('Extrait indisponible pour le moment.');
    expect(artistFetches).toBe(before + 1);
  });

  it('shows a retry message on 503', async () => {
    server.use(
      http.get(`${API}/catalog/artists/:id`, () =>
        problem(503, 'catalog_unavailable', { headers: { 'Retry-After': '30' } }),
      ),
    );
    renderRoute(<CatalogArtistPage />, {
      path: '/discover/artists/:externalId',
      url: '/discover/artists/dz:27',
    });
    expect(await screen.findByText(/momentanément indisponible/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });
});
