import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { beforeEach, describe, expect, it } from 'vitest';
import type { Post, SearchResults } from '@/api/types';
import { hashtagHref } from '@/features/discover/hashtagHref';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { loginAs, renderRoute } from '@/test/utils';
import { HashtagPage } from './HashtagPage';
import { SearchPage } from './SearchPage';

const API = '*/api/v1';

beforeEach(async () => {
  await loginAs('lea@wave.local');
});

const owner = { id: 'o', username: 'owner', displayName: 'Owner', avatarUrl: null };
const artist = (n: number) => ({
  id: `ar${n}`,
  slug: `artiste-${n}`,
  name: `Artiste ${n}`,
  avatarUrl: null,
  bio: null,
  trackCount: n,
  owner,
  moderation: 'visible' as const,
  isOwner: false,
  createdAt: '2026-11-01T10:00:00Z',
});

describe('search tabs (W-43b)', () => {
  it('shows the overview sections, with « Voir tout » opening the typed tab', async () => {
    const seen: (string | null)[] = [];
    server.use(
      http.get(`${API}/search`, ({ request }) => {
        const url = new URL(request.url);
        seen.push(url.searchParams.get('type'));
        if (url.searchParams.get('type') === 'artists')
          return json({ artists: { items: [artist(1), artist(2), artist(3)], nextCursor: null } });
        const body: SearchResults = {
          artists: { items: [artist(1), artist(2)], nextCursor: 'c' },
          hashtags: { items: [{ name: 'lofi', postCount: 12 }], nextCursor: null },
        };
        return json(body);
      }),
    );
    const user = userEvent.setup();
    renderRoute(<SearchPage />, { path: '/discover', url: '/discover?q=art' });
    const artists = await screen.findByRole('list', { name: 'Artistes pour « art »' });
    expect(within(artists).getAllByRole('listitem')).toHaveLength(2);
    expect(screen.getByRole('link', { name: /#lofi/ })).toHaveAttribute('href', '/hashtags/lofi');
    expect(screen.queryByRole('list', { name: /Playlists pour/ })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'Voir tout' }));
    const full = await screen.findByRole('list', { name: 'Artistes pour « art »' });
    await within(full).findByText('Artiste 3');
    expect(screen.getByRole('tab', { name: 'Artistes' })).toHaveAttribute('aria-selected', 'true');
    expect(within(full).getByRole('link', { name: /Artiste 3/ })).toHaveAttribute(
      'href',
      '/a/artiste-3',
    );
    expect(seen).toEqual([null, 'artists']);
  });

  it('switches tabs on click and shows each section empty state', async () => {
    server.use(
      http.get(`${API}/search`, () => json({ playlists: { items: [], nextCursor: null } })),
    );
    const user = userEvent.setup();
    renderRoute(<SearchPage />, { path: '/discover', url: '/discover?q=zzzz&tab=users' });
    await screen.findByText('Personne ne correspond à « zzzz »');
    await user.click(screen.getByRole('tab', { name: 'Playlists' }));
    expect(await screen.findByText('Aucune playlist ne correspond à « zzzz »')).toBeInTheDocument();
  });

  it('opens the Hashtags tab for a #query', async () => {
    renderRoute(<SearchPage />, { path: '/discover', url: '/discover?q=%23lofi' });
    expect(await screen.findByRole('list', { name: 'Hashtags pour « #lofi »' })).toHaveTextContent(
      '#lofi',
    );
    expect(screen.getByRole('tab', { name: 'Hashtags' })).toHaveAttribute('aria-selected', 'true');
  });

  it('points hashtag links to the hashtag page', () => {
    expect(hashtagHref('lofi')).toBe('/hashtags/lofi');
  });
});

function post(id: string, over: Partial<Post> = {}): Post {
  return {
    id,
    author: owner,
    track: {
      id: `t${id}`,
      title: `Titre ${id}`,
      artist: { id: 'a', slug: 'a', name: 'A', avatarUrl: null },
      coverUrl: null,
    },
    visual: null,
    caption: `Légende ${id} #lofi`,
    hashtags: ['lofi'],
    likeCount: 4,
    ...over,
  } as unknown as Post;
}

const mountTag = (name: string) =>
  renderRoute(<HashtagPage />, { path: '/hashtags/:name', url: `/hashtags/${name}` });

describe('hashtag page (W-43b)', () => {
  it('shows the count and the posts, each linking to /posts/:id', async () => {
    server.use(
      http.get(`${API}/hashtags/lofi/posts`, () =>
        json({ items: [post('p1'), post('p2')], nextCursor: null }),
      ),
    );
    mountTag('Lofi');
    expect(await screen.findByRole('heading', { name: '#lofi' })).toBeInTheDocument();
    expect(await screen.findByText('128 publications')).toBeInTheDocument();
    const list = await screen.findByRole('list', { name: 'Publications avec #lofi' });
    expect(within(list).getAllByRole('link')).toHaveLength(2);
    expect(within(list).getAllByRole('link')[0]).toHaveAttribute('href', '/posts/p1');
  });

  it('shows an empty state', async () => {
    mountTag('jazz');
    expect(await screen.findByText('Aucune publication avec #jazz')).toBeInTheDocument();
  });

  it('shows a not-found state on 404', async () => {
    mountTag('inconnu');
    expect(await screen.findByText('#inconnu n’existe pas (ou plus)')).toBeInTheDocument();
  });

  it('shows an error with a retry button', async () => {
    server.use(http.get(`${API}/hashtags/lofi`, () => problem(500, 'internal_error')));
    mountTag('lofi');
    expect(await screen.findByRole('button', { name: 'Réessayer' })).toBeInTheDocument();
  });
});
