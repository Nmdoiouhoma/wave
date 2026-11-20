import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { DiscoverHome, Track, UserListItem } from '@/api/types';
import { mockConfig } from '@/mocks/db';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { SearchPage } from '@/features/search/SearchPage';
import { playerStore } from '@/stores/player';
import { socialStore } from '@/stores/social';
import { loginAs, renderRoute } from '@/test/utils';
import { FeedSideColumn, FollowSuggestionsInline } from './Suggestions';
import { composeDiscover, THIN_ROWS_THRESHOLD } from './compose';

const API = '*/api/v1';

function track(id: string, over: Partial<Track> = {}): Track {
  return {
    id,
    title: `Titre ${id}`,
    artist: { id: `ar-${id}`, slug: `artiste-${id}`, name: `Artiste ${id}`, avatarUrl: null },
    owner: { id: 'o', username: 'owner', displayName: 'Owner', avatarUrl: null },
    genre: { id: 'pop', name: 'Pop' },
    coverUrl: null,
    durationMs: 180_000,
    status: 'ready',
    failureReason: null,
    visibility: 'public',
    allowClips: true,
    moderation: 'visible',
    playCount: 1200,
    likeCount: 3,
    likedByMe: false,
    isOwner: false,
    canClip: true,
    createdAt: new Date(Date.now() - 12 * 60_000).toISOString(),
    ...over,
  } as Track;
}

function user(id: string, over: Partial<UserListItem> = {}): UserListItem {
  return {
    id,
    username: `u${id}`,
    displayName: `Compte ${id}`,
    avatarUrl: null,
    followersCount: 10,
    isMe: false,
    isFollowedByMe: false,
    ...over,
  } as UserListItem;
}

const ids = (prefix: string, n: number) => Array.from({ length: n }, (_, i) => `${prefix}${i + 1}`);

function home(over: Partial<DiscoverHome> = {}): DiscoverHome {
  return {
    forYouTracks: ids('f', 4).map((id) => ({
      track: track(id),
      reasonType: 'artist' as const,
      reasonLabel: `Artiste ${id}`,
    })),
    newTracks: ids('n', 5).map((id) => track(id)),
    trendingTracks: ids('t', 5).map((id) => track(id)),
    genres: [
      { id: 'pop', name: 'Pop' },
      { id: 'rock', name: 'Rock' },
      { id: 'jazz', name: 'Jazz' },
      { id: 'lofi', name: 'Lo-fi' },
    ],
    suggestedUsers: ids('', 6).map((id) => user(id)),
    trendingHashtags: [
      { name: 'lofi', postCount: 120 },
      { name: 'jazz', postCount: 40 },
    ],
    ...over,
  };
}

const serve = (body: DiscoverHome) => server.use(http.get(`${API}/discover`, () => json(body)));

beforeEach(async () => {
  playerStore.setState({ queue: [], index: -1, status: 'idle', error: null });
  await loginAs('lea@wave.local');
});
afterEach(() => {
  socialStore.getState().reset();
  mockConfig.catalog = false;
});

const mount = () => renderRoute(<SearchPage />, { path: '/discover' });

describe('composeDiscover', () => {
  it('takes the hero from Pour toi, never repeated in the rows, and keeps the row order', () => {
    const m = composeDiscover(home());
    expect(m.hero).toMatchObject({ kind: 'for-you', reason: 'Parce que tu écoutes Artiste f1' });
    expect(m.hero?.track.id).toBe('f1');
    expect(m.rows.map((r) => r.id)).toEqual(['for-you', 'new', 'trending']);
    expect(m.rows[0]!.items.map((i) => i.track.id)).toEqual(['f2', 'f3', 'f4']);
  });

  it('falls back to the new tracks, then the trending ones, and drops empty rows', () => {
    const noFy = composeDiscover(home({ forYouTracks: [] }));
    expect(noFy.hero).toMatchObject({ kind: 'new' });
    expect(noFy.hero?.track.id).toBe('n1');
    expect(noFy.rows.map((r) => r.id)).toEqual(['new', 'trending']);
    const onlyTrend = composeDiscover(home({ forYouTracks: [], newTracks: [] }));
    expect(onlyTrend.hero).toMatchObject({ kind: 'trending' });
    expect(onlyTrend.rows.map((r) => r.id)).toEqual(['trending']);
  });

  it('ignores tracks that are not playable', () => {
    const m = composeDiscover(
      home({ forYouTracks: [], newTracks: [track('p', { status: 'processing' }), track('n1')] }),
    );
    expect(m.hero?.track.id).toBe('n1');
  });

  it('is thin under the card threshold only', () => {
    expect(composeDiscover(home()).thin).toBe(false); // 3 + 4 + 5 = 12 cards after the hero
    const thin = composeDiscover(
      home({ forYouTracks: [], trendingTracks: [], newTracks: ids('n', 4).map((i) => track(i)) }),
    );
    expect(thin.thin).toBe(true);
    expect(THIN_ROWS_THRESHOLD).toBe(12);
  });

  it('flags a page with nothing at all', () => {
    expect(
      composeDiscover(
        home({
          forYouTracks: [],
          newTracks: [],
          trendingTracks: [],
          genres: [],
          suggestedUsers: [],
          trendingHashtags: [],
        }),
      ).empty,
    ).toBe(true);
  });
});

describe('Découvrir page', () => {
  it('shows hero, genres, then the rows in order, with reasons, age and rank', async () => {
    serve(home());
    mount();
    const hero = await screen.findByRole('region', { name: 'Morceau mis en avant' });
    expect(within(hero).getByRole('heading', { name: 'Titre f1' })).toBeInTheDocument();
    expect(within(hero).getByText('Parce que tu écoutes Artiste f1')).toBeInTheDocument();
    const regions = screen
      .getAllByRole('region')
      .map((r) => r.getAttribute('aria-label'))
      .filter(Boolean);
    expect(regions.slice(0, 6)).toEqual([
      'Morceau mis en avant',
      'Genres',
      'Pour toi',
      'Nouveautés',
      'En tendance',
      'Comptes à suivre',
    ]);
    expect(screen.getByText('Parce que tu écoutes Artiste f2')).toBeInTheDocument();
    expect(
      within(screen.getByRole('region', { name: 'Nouveautés' })).getAllByText('il y a 12 min'),
    ).toHaveLength(5);
    expect(
      within(screen.getByRole('region', { name: 'En tendance' })).getByText('1'),
    ).toBeInTheDocument();
    // Rich page: 5 accounts, hashtags as chips.
    expect(
      within(screen.getByRole('region', { name: 'Comptes à suivre' })).getAllByRole('listitem'),
    ).toHaveLength(5);
    expect(screen.getByRole('link', { name: /#lofi/ })).toHaveAttribute(
      'href',
      '/discover?q=%23lofi',
    );
  });

  it('moves accounts up (6) and shrinks famous artists when the rows are thin', async () => {
    mockConfig.catalog = true;
    await loginAs('lea@wave.local');
    serve(
      home({ forYouTracks: [], trendingTracks: [], newTracks: ids('n', 4).map((i) => track(i)) }),
    );
    mount();
    await screen.findByRole('region', { name: 'Morceau mis en avant' });
    await screen.findByRole('region', { name: 'Artistes célèbres' });
    expect(screen.queryByRole('region', { name: 'Pour toi' })).toBeNull();
    expect(screen.queryByRole('region', { name: 'En tendance' })).toBeNull();
    expect(
      within(screen.getByRole('region', { name: 'Comptes à suivre' })).getAllByRole('listitem'),
    ).toHaveLength(6);
    // The famous-artists block follows the filler, not the other way round.
    const order = screen.getAllByRole('region').map((r) => r.getAttribute('aria-label'));
    expect(order.indexOf('Comptes à suivre')).toBeLessThan(order.indexOf('Artistes célèbres'));
  });

  it('plays through the global player: one click plays the row, a second pauses', async () => {
    serve(home());
    const u = userEvent.setup();
    mount();
    const row = await screen.findByRole('region', { name: 'Nouveautés' });
    await u.click(within(row).getByRole('button', { name: 'Lire Titre n2, Artiste n2' }));
    const s = playerStore.getState();
    expect(s.queue.map((t) => t.id)).toEqual(['n1', 'n2', 'n3', 'n4', 'n5']);
    expect(s.queue[s.index]!.id).toBe('n2');
    expect(s.status).toBe('loading');
    expect(
      await within(row).findByRole('button', { name: 'Mettre en pause Titre n2, Artiste n2' }),
    ).toHaveAttribute('aria-pressed', 'true');
    expect(within(row).getByText('En cours')).toBeInTheDocument();
    const pause = vi.fn();
    playerStore.setState({ status: 'playing', pause });
    await u.click(within(row).getByRole('button', { name: /Mettre en pause Titre n2/ }));
    expect(pause).toHaveBeenCalledOnce();
  });

  it('plays the hero with its main button', async () => {
    serve(home());
    const u = userEvent.setup();
    mount();
    await u.click(await screen.findByRole('button', { name: 'Écouter Titre f1, Artiste f1' }));
    expect(playerStore.getState().queue[0]!.id).toBe('f1');
    expect(screen.getByRole('button', { name: 'Pause Titre f1, Artiste f1' })).toBeInTheDocument();
  });

  it('says so when a track cannot be played', async () => {
    serve(home());
    const u = userEvent.setup();
    mount();
    const row = await screen.findByRole('region', { name: 'Nouveautés' });
    await u.click(within(row).getByRole('button', { name: /Lire Titre n1/ }));
    playerStore.setState({ status: 'error', error: 'x' });
    expect(await within(row).findByRole('alert')).toHaveTextContent(
      'Lecture impossible, réessaie.',
    );
  });

  it('follows from the accounts block and keeps the row', async () => {
    serve(home());
    server.use(
      http.put(`${API}/users/:username/follow`, () =>
        json({ following: true, followersCount: 11 }),
      ),
    );
    const u = userEvent.setup();
    mount();
    const block = await screen.findByRole('region', { name: 'Comptes à suivre' });
    await u.click(within(block).getByRole('button', { name: 'Suivre Compte 1' }));
    await waitFor(() =>
      expect(
        within(block).getByRole('button', { name: 'Ne plus suivre Compte 1' }),
      ).toBeInTheDocument(),
    );
    expect(within(block).getAllByRole('listitem')).toHaveLength(5);
  });

  it('shows a loading state, then an error with a retry that recovers', async () => {
    let fail = true;
    server.use(
      http.get(`${API}/discover`, () => (fail ? problem(500, 'internal_error') : json(home()))),
    );
    const u = userEvent.setup();
    mount();
    expect(screen.getByRole('status', { name: 'Chargement de Découvrir' })).toBeInTheDocument();
    const alert = await screen.findByRole('alert');
    expect(within(alert).getByRole('button', { name: 'Réessayer' })).toBeInTheDocument();
    fail = false;
    await u.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByRole('region', { name: 'Morceau mis en avant' })).toBeInTheDocument();
  });

  it('shows the empty state when there is nothing to discover', async () => {
    serve(
      home({
        forYouTracks: [],
        newTracks: [],
        trendingTracks: [],
        genres: [],
        suggestedUsers: [],
        trendingHashtags: [],
      }),
    );
    mount();
    expect(await screen.findByText('Rien à découvrir pour l’instant')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Créer un post' })).toHaveAttribute(
      'href',
      '/posts/new',
    );
  });

  it('works against the default mocks (npm run dev:mocks)', async () => {
    mount();
    expect(await screen.findByRole('region', { name: 'Comptes à suivre' })).toBeInTheDocument();
  });
});

describe('feed side columns and empty Abonnements', () => {
  it('lists 4 accounts and 5 hashtags in the column, sharing the one query', async () => {
    let calls = 0;
    server.use(http.get(`${API}/discover`, () => (calls++, json(home()))));
    renderRoute(
      <>
        <FeedSideColumn side="left" />
        <FeedSideColumn side="right" />
      </>,
    );
    await screen.findAllByRole('button', { name: 'Suivre Compte 1' });
    const cols = screen.getAllByRole('complementary', { name: 'Suggestions' });
    expect(within(cols[0]!).getAllByRole('listitem').length).toBeGreaterThanOrEqual(6);
    expect(within(cols[0]!).getByRole('button', { name: 'Suivre Compte 1' })).toBeInTheDocument();
    expect(within(cols[0]!).queryByText(/abonnés?$/)).toBeNull(); // narrow column: no counts
    expect(calls).toBe(1);
  });

  it('shows the suggestions inline instead of an empty state, and an error with retry', async () => {
    let fail = true;
    server.use(
      http.get(`${API}/discover`, () => (fail ? problem(500, 'internal_error') : json(home()))),
    );
    const u = userEvent.setup();
    renderRoute(<FollowSuggestionsInline />);
    const retry = await screen.findByRole('button', { name: 'Réessayer' });
    fail = false;
    await u.click(retry);
    expect(
      await screen.findByRole('heading', { name: 'Ton fil d’abonnements est vide' }),
    ).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Suivre Compte 1' })).toBeInTheDocument();
  });
});
