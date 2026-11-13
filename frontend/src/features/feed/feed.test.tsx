import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { PostPage as FeedPageData } from '@/api/types';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { playerStore } from '@/stores/player';
import { FakeAudio } from '@/test/fakeAudio';
import { loginAs, renderRoute } from '@/test/utils';
import { FeedPage } from './FeedPage';
import { setClipAudioFactory, type ClipAudio } from './feedPlayback';
import { PostPage } from './PostPage';
import { makePost } from './testPosts';

const API = '*/api/v1';
let audios: FakeAudio[] = [];

beforeEach(async () => {
  audios = [];
  setClipAudioFactory(() => {
    const a = new FakeAudio();
    audios.push(a);
    return a as unknown as ClipAudio;
  });
  await loginAs('lea@wave.local');
});
afterEach(() => {
  setClipAudioFactory(null);
  vi.restoreAllMocks();
  playerStore.setState({ queue: [], index: -1, status: 'idle' });
});

/** Serves `pages` in order by cursor (`c1`, `c2`…); records the cursors asked for. */
function serveFeed(source: 'for-you' | 'following', pages: FeedPageData[]) {
  const asked: (string | null)[] = [];
  server.use(
    http.get(`${API}/feed/${source}`, ({ request }) => {
      const cursor = new URL(request.url).searchParams.get('cursor');
      asked.push(cursor);
      if (cursor === 'stale') return problem(400, 'invalid_cursor');
      const i = cursor ? Number(cursor.slice(1)) : 0;
      return json(pages[i] ?? { items: [], nextCursor: null });
    }),
  );
  return asked;
}

const playingAudio = () => audios.find((a) => !a.paused) ?? null;
const activeItem = () => document.querySelector('article[aria-current="true"]') as HTMLElement;

describe('wide feed layout (FB-26)', () => {
  afterEach(() => vi.restoreAllMocks());

  const viewport = (matches: boolean) =>
    vi.spyOn(window, 'matchMedia').mockImplementation(
      (query: string) =>
        ({
          matches,
          media: query,
          addEventListener: () => {},
          removeEventListener: () => {},
        }) as unknown as MediaQueryList,
    );

  it('shows track info and comments of the active post in a right panel from lg up', async () => {
    viewport(true);
    const p1 = makePost(1);
    serveFeed('for-you', [{ items: [p1], nextCursor: null }]);
    renderRoute(<FeedPage which="for-you" />);
    const panel = await screen.findByRole('complementary', { name: 'Détails de l’extrait' });
    expect(within(panel).getByText(p1.track.title)).toBeInTheDocument();
    expect(within(panel).getByRole('heading', { name: 'Commentaires' })).toBeInTheDocument();
  });

  it('has no panel on a phone', async () => {
    viewport(false);
    serveFeed('for-you', [{ items: [makePost(1)], nextCursor: null }]);
    renderRoute(<FeedPage which="for-you" />);
    await screen.findByRole('feed');
    expect(screen.queryByRole('complementary', { name: 'Détails de l’extrait' })).toBeNull();
  });
});

describe('vertical feed (W-34)', () => {
  it('dedupes pages, fetches after a short page and plays the first clip, pausing the player', async () => {
    const pause = vi.fn();
    playerStore.setState({ status: 'playing', pause });
    const [p1, p2, p3] = [makePost(1), makePost(2), makePost(3)];
    const asked = serveFeed('for-you', [
      { items: [p1, p2], nextCursor: 'c1' },
      { items: [p2, p3], nextCursor: null },
    ]);
    renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(screen.getAllByRole('article')).toHaveLength(3));
    expect(asked).toEqual([null, 'c1']);
    expect(
      within(screen.getByRole('navigation', { name: 'Fils' })).getByRole('link', {
        name: 'Pour toi',
      }),
    ).toHaveAttribute('aria-current', 'page');
    expect(activeItem()).toHaveAccessibleName(/Morceau 1/);
    await waitFor(() => expect(playingAudio()?.src).toBe(p1.clip!.url));
    expect(pause).toHaveBeenCalled();
    // Caption as plain text, links to author and track.
    expect(within(activeItem()).getByText('Légende 1 #rap')).toBeInTheDocument();
    expect(within(activeItem()).getAllByRole('link', { name: /Nour/ })[0]).toHaveAttribute(
      'href',
      '/u/nour',
    );
    expect(within(activeItem()).getByRole('link', { name: /Morceau 1/ })).toHaveAttribute(
      'href',
      `/t/${p1.track.id}`,
    );
  });

  it('moves to the next clip and sends the feed listen of the one left', async () => {
    const listens: unknown[] = [];
    server.use(
      http.post(`${API}/tracks/:id/listens`, async ({ request }) => {
        listens.push(await request.json());
        return json({ counted: false, playCount: 0 });
      }),
    );
    const [p1, p2] = [makePost(1), makePost(2)];
    serveFeed('for-you', [{ items: [p1, p2], nextCursor: null }]);
    const user = userEvent.setup();
    renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(playingAudio()?.src).toBe(p1.clip!.url));
    playingAudio()!.emit('playing');
    playingAudio()!.advance(2_000);
    await user.click(screen.getByRole('button', { name: 'Extrait suivant' }));
    expect(activeItem()).toHaveAccessibleName(/Morceau 2/);
    await waitFor(() => expect(playingAudio()?.src).toBe(p2.clip!.url));
    await waitFor(() =>
      expect(listens).toEqual([{ listenedMs: 2_000, source: 'feed', postId: p1.id }]),
    );
  });

  it('restarts without cursor on invalid_cursor', async () => {
    const asked: (string | null)[] = [];
    server.use(
      http.get(`${API}/feed/for-you`, ({ request }) => {
        const cursor = new URL(request.url).searchParams.get('cursor');
        asked.push(cursor);
        if (cursor === 'stale') return problem(400, 'invalid_cursor');
        // First call hands out a cursor that has since expired; the restart ends the feed.
        return json(
          asked.length === 1
            ? { items: [makePost(1)], nextCursor: 'stale' }
            : { items: [makePost(1), makePost(2)], nextCursor: null },
        );
      }),
    );
    renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(screen.getAllByRole('article')).toHaveLength(2));
    expect(asked).toEqual([null, 'stale', null]);
  });

  it('ends with "Tu as tout vu" and Recommencer opens a new session', async () => {
    const asked = serveFeed('for-you', [{ items: [makePost(1)], nextCursor: null }]);
    const user = userEvent.setup();
    renderRoute(<FeedPage which="for-you" />);
    expect(await screen.findByText('Tu as tout vu')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Recommencer' }));
    await waitFor(() => expect(asked).toEqual([null, null]));
    expect(await screen.findByText('Tu as tout vu')).toBeInTheDocument();
  });

  it('shows an empty state linking to Découvrir', async () => {
    serveFeed('following', [{ items: [], nextCursor: null }]);
    renderRoute(<FeedPage which="following" />, { path: '/following' });
    expect(await screen.findByText('Rien de neuf dans tes abonnements')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Découvrir' })).toHaveAttribute('href', '/discover');
    expect(screen.getByRole('link', { name: 'Abonnements' })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });

  it('likes on double tap (optimistic) and rolls back on error', async () => {
    const post = makePost(1);
    serveFeed('for-you', [{ items: [post], nextCursor: null }]);
    let fail = false;
    server.use(
      http.put(`${API}/posts/:id/like`, () =>
        fail ? problem(404, 'not_found') : json({ liked: true, likeCount: 4 }),
      ),
      http.delete(`${API}/posts/:id/like`, () => problem(404, 'not_found')),
    );
    const user = userEvent.setup();
    renderRoute(<FeedPage which="for-you" />);
    const item = await screen.findByRole('article');
    await user.dblClick(within(item).getByRole('button', { name: /Lire|pause/ }));
    const like = within(item).getByRole('button', { name: 'Je n’aime plus' });
    expect(like).toHaveAttribute('aria-pressed', 'true');
    expect(like).toHaveTextContent('4');
    // Unlike fails → back to liked.
    fail = true;
    await user.click(like);
    await waitFor(() =>
      expect(within(item).getByRole('button', { name: 'Je n’aime plus' })).toHaveTextContent('4'),
    );
  });

  it('pauses on tap and on tab hide', async () => {
    const post = makePost(1);
    serveFeed('for-you', [{ items: [post], nextCursor: null }]);
    const user = userEvent.setup();
    renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(playingAudio()).not.toBeNull());
    const el = playingAudio()!;
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(el.paused).toBe(true);
    visibility.mockReturnValue('visible');
    document.dispatchEvent(new Event('visibilitychange'));
    expect(el.paused).toBe(false);
    await user.click(within(activeItem()).getByRole('button', { name: /Lire|pause/ }));
    await waitFor(() => expect(el.paused).toBe(true));
  });

  it('offers "Utiliser ce son" only when the track can be clipped', async () => {
    const yes = makePost(1);
    const no = makePost(2, { track: { ...makePost(2).track, canClip: false } });
    serveFeed('for-you', [{ items: [yes, no], nextCursor: null }]);
    renderRoute(<FeedPage which="for-you" />);
    const [a, b] = await screen.findAllByRole('article');
    expect(within(a!).getByRole('link', { name: 'Utiliser ce son' })).toHaveAttribute(
      'href',
      `/posts/new?trackId=${yes.track.id}`,
    );
    expect(within(b!).queryByRole('link', { name: 'Utiliser ce son' })).not.toBeInTheDocument();
  });
});

describe('single post (W-34)', () => {
  it('shows one post in the viewer', async () => {
    const post = makePost(7);
    server.use(http.get(`${API}/posts/:id`, () => json(post)));
    renderRoute(<PostPage />, { path: '/posts/:id', url: `/posts/${post.id}` });
    expect(await screen.findByRole('article')).toHaveAccessibleName(/Morceau 7/);
    await waitFor(() => expect(playingAudio()?.src).toBe(post.clip!.url));
    expect(screen.queryByText('Tu as tout vu')).not.toBeInTheDocument();
  });

  it('navigates with keys whatever has the focus, except in a field (FB-24 A1)', async () => {
    serveFeed('for-you', [{ items: [makePost(1), makePost(2), makePost(3)], nextCursor: null }]);
    const user = userEvent.setup();
    renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(playingAudio()).not.toBeNull());
    // Nothing focused yet (page just opened).
    await user.keyboard('j');
    expect(activeItem()).toHaveAccessibleName(/Morceau 2/);
    // A click on the post focuses its play button, not the scroller.
    within(activeItem())
      .getByRole('button', { name: /Mettre en pause|Lire l’extrait/ })
      .focus();
    await user.keyboard('{ArrowDown}');
    expect(activeItem()).toHaveAccessibleName(/Morceau 3/);
    await user.keyboard('k');
    expect(activeItem()).toHaveAccessibleName(/Morceau 2/);
  });

  it('moves one post per wheel gesture (FB-24 A1)', async () => {
    serveFeed('for-you', [{ items: [makePost(1), makePost(2), makePost(3)], nextCursor: null }]);
    renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(playingAudio()).not.toBeNull());
    const feed = screen.getByRole('feed');
    act(() => {
      fireEvent.wheel(feed, { deltaY: 120 });
      fireEvent.wheel(feed, { deltaY: 120 });
      fireEvent.wheel(feed, { deltaY: 80 });
    });
    expect(activeItem()).toHaveAccessibleName(/Morceau 2/);
  });

  it('comes back to the same post and session after leaving the feed (FB-24 A5)', async () => {
    const asked = serveFeed('for-you', [
      { items: [makePost(1), makePost(2), makePost(3)], nextCursor: null },
    ]);
    const user = userEvent.setup();
    const { router } = renderRoute(<FeedPage which="for-you" />);
    await waitFor(() => expect(playingAudio()).not.toBeNull());
    await user.keyboard('jj');
    expect(activeItem()).toHaveAccessibleName(/Morceau 3/);
    await act(() => router.navigate('/u/nour'));
    expect(screen.getByText('autre page')).toBeInTheDocument();
    await act(() => router.navigate(-1));
    expect(await screen.findByRole('feed')).toBeInTheDocument();
    expect(activeItem()).toHaveAccessibleName(/Morceau 3/);
    expect(asked).toEqual([null]);
  });

  it('says the post is gone on 404', async () => {
    server.use(http.get(`${API}/posts/:id`, () => problem(404, 'not_found')));
    renderRoute(<PostPage />, {
      path: '/posts/:id',
      url: '/posts/0192bbbb-0000-7000-8000-000000000099',
    });
    expect(await screen.findByText('Ce post n’est plus disponible')).toBeInTheDocument();
  });
});
