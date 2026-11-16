// W-81 (ADR 015): repost button, attribution header and profile tab.
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http } from 'msw';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ProblemCode } from '@/api/types';
import { Toaster } from '@/components/ui/Toaster';
import { ProfilePage } from '@/features/profile/ProfilePage';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { FakeAudio } from '@/test/fakeAudio';
import { loginAs, renderRoute } from '@/test/utils';
import { FeedPage } from './FeedPage';
import { setClipAudioFactory, type ClipAudio } from './feedPlayback';
import { PostPage } from './PostPage';
import { makePost } from './testPosts';

const API = '*/api/v1';
const KENJI = { id: 'k', username: 'kenji', displayName: 'Kenji Sato', avatarUrl: null };

beforeEach(async () => {
  setClipAudioFactory(() => new FakeAudio() as unknown as ClipAudio);
  await loginAs('lea@wave.local');
});
afterEach(() => setClipAudioFactory(null));

function renderFeed(source: 'for-you' | 'following', post: ReturnType<typeof makePost>) {
  server.use(http.get(`${API}/feed/${source}`, () => json({ items: [post], nextCursor: null })));
  renderRoute(
    <>
      <FeedPage which={source} />
      <Toaster />
    </>,
  );
}

describe('repost button (W-81)', () => {
  it('is optimistic, then confirmed by the server, and can be undone', async () => {
    renderFeed('for-you', makePost(1, { repostCount: 2 }));
    server.use(
      http.put(`${API}/posts/:id/repost`, async () => {
        await delay(30);
        return json({ reposted: true, repostCount: 5 });
      }),
      http.delete(`${API}/posts/:id/repost`, () => json({ reposted: false, repostCount: 4 })),
    );
    const user = userEvent.setup();
    const item = await screen.findByRole('article');
    const btn = within(item).getByRole('button', { name: 'Reposter' });
    expect(btn).toHaveAttribute('aria-pressed', 'false');
    expect(btn).toHaveTextContent('2');
    await user.click(btn);
    // Before the server answers.
    const undo = within(item).getByRole('button', { name: 'Annuler le repost' });
    expect(undo).toHaveAttribute('aria-pressed', 'true');
    expect(undo).toHaveTextContent('3');
    await waitFor(() => expect(undo).toHaveTextContent('5'));
    await user.click(undo);
    await waitFor(() =>
      expect(within(item).getByRole('button', { name: 'Reposter' })).toHaveTextContent('4'),
    );
  });

  it('is hidden when the post cannot be reposted and is not reposted by me', async () => {
    renderFeed('for-you', makePost(1, { canRepost: false, repostedByMe: false }));
    const item = await screen.findByRole('article');
    expect(within(item).getByRole('button', { name: 'J’aime' })).toBeInTheDocument();
    expect(within(item).queryByRole('button', { name: /repost/i })).toBeNull();
  });

  it('still offers the undo when reposted by me on a post no longer repostable', async () => {
    renderFeed('for-you', makePost(1, { canRepost: false, repostedByMe: true, repostCount: 1 }));
    server.use(
      http.delete(`${API}/posts/:id/repost`, () => json({ reposted: false, repostCount: 0 })),
    );
    const item = await screen.findByRole('article');
    await userEvent.setup().click(within(item).getByRole('button', { name: 'Annuler le repost' }));
    // Undone and not repostable: the button goes away.
    await waitFor(() => expect(within(item).queryByRole('button', { name: /repost/i })).toBeNull());
  });

  it.each<[number, ProblemCode, RegExp]>([
    [403, 'email_not_verified', /Confirme ton adresse e-mail pour pouvoir reposter/],
    [422, 'cannot_repost_own', /Tu ne peux pas reposter ton propre post/],
    [422, 'repost_not_allowed', /Ce post n’est pas public/],
    [429, 'rate_limited', /Trop de reposts d’affilée\. Réessaie dans 2 min\./],
  ])('rolls back on %i %s with a French message', async (status, code, message) => {
    renderFeed('for-you', makePost(1, { repostCount: 2 }));
    server.use(
      http.put(`${API}/posts/:id/repost`, () =>
        problem(status, code, status === 429 ? { headers: { 'Retry-After': '120' } } : {}),
      ),
    );
    const item = await screen.findByRole('article');
    await userEvent.setup().click(within(item).getByRole('button', { name: 'Reposter' }));
    expect(await screen.findByText(message)).toBeInTheDocument();
    const btn = within(item).getByRole('button', { name: 'Reposter' });
    expect(btn).toHaveAttribute('aria-pressed', 'false');
    expect(btn).toHaveTextContent('2');
  });

  it('is shown on the post detail page', async () => {
    const post = makePost(7, { repostedByMe: true, repostCount: 12 });
    server.use(http.get(`${API}/posts/:id`, () => json(post)));
    renderRoute(<PostPage />, { path: '/posts/:id', url: `/posts/${post.id}` });
    const item = await screen.findByRole('article');
    const btn = within(item).getByRole('button', { name: 'Annuler le repost' });
    expect(btn).toHaveTextContent('12');
    // `repostedBy` is null outside the following feed and the reposts tab.
    expect(within(item).queryByText(/Reposté par/)).toBeNull();
  });
});

describe('"Reposté par" header (W-81)', () => {
  it('names the reposter and links to their profile in the following feed', async () => {
    renderFeed('following', makePost(1, { repostedBy: KENJI, repostedAt: '2026-11-15T10:00:00Z' }));
    const item = await screen.findByRole('article');
    expect(within(item).getByRole('link', { name: 'Reposté par Kenji Sato' })).toHaveAttribute(
      'href',
      '/u/kenji',
    );
  });

  it('is absent when the latest activity is the authorship', async () => {
    renderFeed('following', makePost(1));
    const item = await screen.findByRole('article');
    expect(within(item).queryByText(/Reposté par/)).toBeNull();
  });
});

describe('profile "Reposts" tab (W-81)', () => {
  it('lists the reposts of the user by pages, each linking to its post', async () => {
    const p1 = makePost(1, { repostedBy: KENJI, repostedAt: '2026-11-15T10:00:00Z' });
    const p2 = makePost(2, {
      repostedBy: KENJI,
      repostedAt: '2026-11-14T10:00:00Z',
      caption: null,
    });
    const asked: (string | null)[] = [];
    server.use(
      http.get(`${API}/users/:username/reposts`, ({ request, params }) => {
        expect(params.username).toBe('kenji');
        const cursor = new URL(request.url).searchParams.get('cursor');
        asked.push(cursor);
        return json(cursor ? { items: [p2], nextCursor: null } : { items: [p1], nextCursor: 'c1' });
      }),
    );
    renderRoute(<ProfilePage />, { path: '/u/:username', url: '/u/kenji' });
    const user = userEvent.setup();
    await user.click(await screen.findByRole('tab', { name: 'Reposts' }));
    const list = await screen.findByRole('list', { name: 'Reposts' });
    expect(await within(list).findByRole('link', { name: /Légende 1 #rap/ })).toHaveAttribute(
      'href',
      `/posts/${p1.id}`,
    );
    await user.click(screen.getByRole('button', { name: 'Charger plus' }));
    // No caption → the track title.
    expect(await within(list).findByRole('link', { name: /^Morceau 2/ })).toHaveAttribute(
      'href',
      `/posts/${p2.id}`,
    );
    expect(asked).toEqual([null, 'c1']);
  });

  it('shows an empty state when nothing was reposted', async () => {
    renderRoute(<ProfilePage />, { path: '/u/:username', url: '/u/kenji' });
    await userEvent.setup().click(await screen.findByRole('tab', { name: 'Reposts' }));
    expect(await screen.findByText('Aucun repost')).toBeInTheDocument();
  });
});
