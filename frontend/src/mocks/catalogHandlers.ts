// MSW handlers for the external catalogue (ADR 014, W-86). Fixture data only, no network.
import { http } from 'msw';
import type { CatalogArtist, CatalogArtistList, CatalogArtistSummary } from '@/api/types';
import { json, problem } from './handlerUtils';
import { mockConfig } from './db';

const API = '*/api/v1';
const ATTRIBUTION = {
  text: 'Données et extraits : Deezer',
  url: 'https://www.deezer.com',
} as const;

const ARTISTS: CatalogArtistSummary[] = [
  {
    id: 'dz:27',
    name: 'Daft Punk',
    pictureUrl: null,
    fanCount: 4_800_000,
    deezerUrl: 'https://www.deezer.com/artist/27',
  },
  {
    id: 'dz:13',
    name: 'Eminem',
    pictureUrl: null,
    fanCount: 9_100_000,
    deezerUrl: 'https://www.deezer.com/artist/13',
  },
];

function artist(id: string): CatalogArtist | null {
  const a = ARTISTS.find((x) => x.id === id);
  if (!a) return null;
  return {
    ...a,
    attribution: ATTRIBUTION,
    topTracks: [1, 2, 3].map((n) => ({
      id: `dz:${Number(id.slice(3)) * 100 + n}`,
      title: `${a.name} titre ${n}`,
      durationSec: 200 + n,
      previewUrl: `https://cdnt-preview.dzcdn.net/mock/${n}.mp3`,
      albumTitle: 'Album',
      coverUrl: null,
      deezerUrl: `${a.deezerUrl}#${n}`,
    })),
  };
}

const list = (items: CatalogArtistSummary[]): CatalogArtistList => ({
  available: true,
  items,
  attribution: ATTRIBUTION,
});

export const catalogHandlers = [
  http.get(`${API}/catalog/charts/artists`, async () => {
    if (!mockConfig.catalog) return problem(404, 'not_found');
    return json(list(ARTISTS));
  }),
  http.get(`${API}/catalog/artists`, async ({ request }) => {
    if (!mockConfig.catalog) return problem(404, 'not_found');
    const q = (new URL(request.url).searchParams.get('q') ?? '').toLowerCase();
    return json(list(ARTISTS.filter((a) => a.name.toLowerCase().includes(q))));
  }),
  http.get(`${API}/catalog/artists/:id`, async ({ params }) => {
    const a = mockConfig.catalog ? artist(String(params.id)) : null;
    return a ? json(a) : problem(404, 'not_found');
  }),
];
