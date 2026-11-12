import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Comment } from '@/api/types';
import { USER_IDS } from '@/mocks/fixtures';
import { json, problem } from '@/mocks/handlerUtils';
import { server } from '@/mocks/server';
import { sessionStore } from '@/stores/session';
import { toastStore } from '@/stores/toast';
import { loginAs, renderRoute } from '@/test/utils';
import { setClipAudioFactory, type ClipAudio } from '@/features/feed/feedPlayback';
import { FakeAudio } from '@/test/fakeAudio';
import { FeedPage } from '@/features/feed/FeedPage';
import { makePost } from '@/features/feed/testPosts';
import { CommentsPanel } from './CommentsPanel';
import { timeAgo } from './commentUtils';

const API = '*/api/v1';
const POST = makePost(1);

function makeComment(n: number, over: Partial<Comment> = {}): Comment {
  return {
    id: `0192dddd-0000-7000-8000-${String(n).padStart(12, '0')}`,
    postId: POST.id,
    author: { id: 'u-kenji', username: 'kenji', displayName: 'Kenji', avatarUrl: null },
    body: `Commentaire ${n}`,
    moderation: 'visible',
    createdAt: new Date().toISOString(),
    canDelete: false,
    ...over,
  } as Comment;
}

/** Serves comment pages by cursor (`c1`, `c2`…). */
function serveComments(pages: Comment[][]) {
  server.use(
    http.get(`${API}/posts/:id/comments`, ({ request }) => {
      const cursor = new URL(request.url).searchParams.get('cursor');
      const i = cursor ? Number(cursor.slice(1)) : 0;
      return json({ items: pages[i] ?? [], nextCursor: i + 1 < pages.length ? `c${i + 1}` : null });
    }),
  );
}

const toasts = () => toastStore.getState().toasts.map((t) => t.message);

beforeEach(async () => {
  toastStore.setState({ toasts: [] });
  await loginAs('lea@wave.local');
});
afterEach(() => vi.restoreAllMocks());

describe('comments (W-36)', () => {
  it('lists pages, renders bodies as plain text and loads more', async () => {
    serveComments([
      [makeComment(1, { body: '<b>gras</b> <img src=x onerror=alert(1)>' })],
      [makeComment(2)],
    ]);
    renderRoute(<CommentsPanel postId={POST.id} onCountDelta={() => {}} onReport={() => {}} />);
    expect(await screen.findByText('<b>gras</b> <img src=x onerror=alert(1)>')).toBeInTheDocument();
    expect(document.querySelector('b, img[src="x"]')).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: 'Charger plus' }));
    expect(await screen.findByText('Commentaire 2')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Charger plus' })).toBeNull();
  });

  it('posts a comment on top with an optimistic count, then reverts on 429 with the wait', async () => {
    serveComments([[makeComment(1)]]);
    let calls = 0;
    server.use(
      http.post(`${API}/posts/:id/comments`, async ({ request }) => {
        calls++;
        if (calls === 2) return problem(429, 'rate_limited', { headers: { 'Retry-After': '42' } });
        const { body } = (await request.json()) as { body: string };
        return json(
          makeComment(9, {
            body,
            author: { id: USER_IDS.lea, username: 'lea', displayName: 'Léa', avatarUrl: null },
            canDelete: true,
          }),
          201,
        );
      }),
    );
    const delta = vi.fn();
    renderRoute(<CommentsPanel postId={POST.id} onCountDelta={delta} onReport={() => {}} />);
    await screen.findByText('Commentaire 1');
    const box = screen.getByLabelText('Ton commentaire');
    await userEvent.type(box, '  Trop bien  ');
    await userEvent.click(screen.getByRole('button', { name: 'Publier' }));
    const list = screen.getByRole('list', { name: 'Commentaires' });
    await waitFor(() =>
      expect(within(list).getAllByRole('listitem')[0]).toHaveTextContent('Trop bien'),
    );
    expect(box).toHaveValue('');
    expect(delta.mock.calls).toEqual([[1]]);

    await userEvent.type(box, 'Encore');
    await userEvent.click(screen.getByRole('button', { name: 'Publier' }));
    expect(
      await screen.findByText(/Tu commentes trop vite\. Réessaie dans 42 s\./),
    ).toBeInTheDocument();
    expect(delta.mock.calls).toEqual([[1], [1], [-1]]);
    expect(box).toHaveValue('Encore');
  });

  it('blocks a body over 500 characters', async () => {
    serveComments([[]]);
    renderRoute(<CommentsPanel postId={POST.id} onCountDelta={() => {}} onReport={() => {}} />);
    await screen.findByText(/Aucun commentaire/);
    const box = screen.getByLabelText('Ton commentaire');
    await userEvent.click(box);
    await userEvent.paste('a'.repeat(501));
    expect(screen.getByText('501 / 500')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Publier' })).toBeDisabled();
  });

  it('deletes only when canDelete, and offers report only on others’ comments', async () => {
    const mine = makeComment(1, {
      author: { id: USER_IDS.lea, username: 'lea', displayName: 'Léa', avatarUrl: null },
      canDelete: true,
    });
    const other = makeComment(2);
    serveComments([[mine, other]]);
    server.use(http.delete(`${API}/comments/:id`, () => new Response(null, { status: 204 })));
    const delta = vi.fn();
    const onReport = vi.fn();
    renderRoute(<CommentsPanel postId={POST.id} onCountDelta={delta} onReport={onReport} />);

    await userEvent.click(
      await screen.findByRole('button', { name: 'Options du commentaire de Kenji' }),
    );
    expect(screen.queryByRole('button', { name: 'Supprimer' })).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: 'Signaler' }));
    expect(onReport).toHaveBeenCalledWith(other);

    await userEvent.click(screen.getByRole('button', { name: 'Options du commentaire de Léa' }));
    expect(screen.queryByRole('button', { name: 'Signaler' })).toBeNull();
    await userEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
    await waitFor(() => expect(screen.queryByText('Commentaire 1')).toBeNull());
    expect(delta).toHaveBeenCalledWith(-1);
    await waitFor(() => expect(toasts()).toContain('Commentaire supprimé'));
  });

  it('offers no report before the session user is known (W-37 F24)', async () => {
    sessionStore.setState({ user: null });
    // The profile never arrives: the window where the author check cannot run yet.
    server.use(http.get(`${API}/me`, () => new Promise<Response>(() => {})));
    serveComments([[makeComment(1)]]);
    renderRoute(<CommentsPanel postId={POST.id} onCountDelta={vi.fn()} onReport={vi.fn()} />);

    expect(await screen.findByText('Commentaire 1')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Options du commentaire de Kenji' })).toBeNull();
  });

  it('restores a comment whose deletion fails', async () => {
    serveComments([[makeComment(1, { canDelete: true })]]);
    server.use(http.delete(`${API}/comments/:id`, () => problem(403, 'forbidden')));
    const delta = vi.fn();
    renderRoute(<CommentsPanel postId={POST.id} onCountDelta={delta} onReport={() => {}} />);
    await userEvent.click(await screen.findByRole('button', { name: /Options du commentaire/ }));
    await userEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
    await waitFor(() => expect(delta.mock.calls).toEqual([[-1], [1]]));
    expect(screen.getByText('Commentaire 1')).toBeInTheDocument();
  });

  it('formats relative dates in French', () => {
    const now = Date.parse('2026-11-12T12:00:00Z');
    expect(timeAgo('2026-11-12T11:59:30Z', now)).toBe('à l’instant');
    expect(timeAgo('2026-11-12T11:57:00Z', now)).toBe('il y a 3 minutes');
    expect(timeAgo('2026-11-11T12:00:00Z', now)).toBe('hier');
  });
});

describe('comments sheet in the feed (W-36)', () => {
  beforeEach(() => {
    setClipAudioFactory(() => new FakeAudio() as unknown as ClipAudio);
  });
  afterEach(() => setClipAudioFactory(null));

  it('opens over the feed, keeps j/k for the textarea and bumps the count', async () => {
    const posts = [makePost(1, { commentCount: 3 }), makePost(2)];
    server.use(http.get(`${API}/feed/for-you`, () => json({ items: posts, nextCursor: null })));
    serveComments([[]]);
    server.use(
      http.post(`${API}/posts/:id/comments`, () => json(makeComment(5, { body: 'jk' }), 201)),
    );
    renderRoute(<FeedPage which="for-you" />);
    const first = await screen.findByRole('article', { name: /Morceau 1/ });
    await userEvent.click(within(first).getByRole('button', { name: 'Commentaires' }));
    const dialog = await screen.findByRole('dialog', { name: 'Commentaires' });
    expect(first.contains(dialog)).toBe(false);
    const box = within(dialog).getByLabelText('Ton commentaire');
    await userEvent.type(box, 'jk');
    expect(box).toHaveValue('jk');
    expect(first).toHaveAttribute('aria-current', 'true');
    await userEvent.click(within(dialog).getByRole('button', { name: 'Publier' }));
    await within(dialog).findByText('jk', { selector: 'p' });
    expect(within(first).getByRole('button', { name: 'Commentaires' })).toHaveTextContent('4');
    await act(async () => {
      await userEvent.click(within(dialog).getByRole('button', { name: 'Fermer' }));
    });
    expect(screen.queryByRole('dialog')).toBeNull();
  });
});
