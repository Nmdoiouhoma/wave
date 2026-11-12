import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { Post } from '@/api/types';
import { toTrack, findTrack } from '@/mocks/db';
import { findUserByUsername } from '@/mocks/db';
import { server } from '@/mocks/server';
import { loginAs, renderRoute } from '@/test/utils';
import { PostComposerPage } from './PostComposerPage';

vi.mock('./postRules', async (orig) => ({
  ...(await orig<Record<string, unknown>>()),
  POST_POLL_INTERVAL_MS: 20,
  POST_POLL_TIMEOUT_MS: 300,
  readVideoDuration: vi.fn(async () => 20_000),
}));
import { readVideoDuration } from './postRules';

const BRUME = '0192f3a2-0000-7000-8000-000000000001'; // Léa, public, 48 s
const PRIVEE = '0192f3a2-0000-7000-8000-000000000004'; // Léa, private
const FAILED = '0192f3a2-0000-7000-8000-000000000005'; // Léa, failed
const POST_ID = '0192f3a2-0000-7000-8000-0000000000aa';

function post(over: Partial<Post> = {}): Post {
  const lea = findUserByUsername('lea')!;
  return {
    id: POST_ID,
    author: { id: lea.id, username: 'lea', displayName: 'Léa', avatarUrl: null },
    track: toTrack(findTrack(BRUME)!, lea),
    clipStartMs: 0,
    clipDurationMs: 30_000,
    clip: null,
    visual: null,
    caption: null,
    hashtags: [],
    status: 'processing',
    failureReason: null,
    moderation: 'visible',
    likeCount: 0,
    commentCount: 0,
    viewCount: 0,
    likedByMe: false,
    isOwner: true,
    createdAt: '2026-11-12T10:00:00Z',
    ...over,
  } as Post;
}

const file = (name: string, type: string, size = 2048) =>
  new File([new Uint8Array(size)], name, { type });

async function openComposer(url = `/posts/new?trackId=${BRUME}`) {
  await loginAs('lea@wave.local');
  const utils = renderRoute(<PostComposerPage />, { path: '/posts/new', url });
  const user = userEvent.setup({ applyAccept: false });
  return { ...utils, user };
}

describe('post composer (W-35)', () => {
  beforeEach(() => vi.mocked(readVideoDuration).mockResolvedValue(20_000));

  it('is replaced by the verify message while the email is unverified', async () => {
    await loginAs('nour@wave.local');
    renderRoute(<PostComposerPage />, { path: '/posts/new', url: '/posts/new' });
    expect(
      await screen.findByText('Confirme ton adresse e-mail pour publier un extrait.'),
    ).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Publier' })).not.toBeInTheDocument();
  });

  it('shows no form when the track in the query cannot be clipped', async () => {
    await openComposer(`/posts/new?trackId=${FAILED}`);
    expect(await screen.findByText(/Tu ne peux pas créer d’extrait de ce morceau/)).toBeVisible();
    expect(screen.queryByRole('button', { name: 'Publier' })).not.toBeInTheDocument();
  });

  it('lists only own ready tracks, then opens the form for the chosen one', async () => {
    const { user } = await openComposer('/posts/new');
    await user.click(await screen.findByRole('radio', { name: /Brume sur le port/ }));
    expect(screen.queryByRole('radio', { name: /Session du jeudi/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('radio', { name: /Aube/ })).not.toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Publier' })).toBeEnabled();
    expect(screen.getByLabelText('Début de l’extrait')).toBeInTheDocument();
  });

  it('keeps start + duration inside the track', async () => {
    await openComposer();
    const duration = await screen.findByLabelText(/Durée/);
    expect(duration).toHaveAttribute('max', '48000');
    const start = screen.getByLabelText('Début de l’extrait');
    expect(start).toHaveAttribute('max', '18000'); // 48 s - default 30 s
  });

  it('shows the audience warning above Publier for an own non-public track', async () => {
    await openComposer(`/posts/new?trackId=${PRIVEE}`);
    expect(await screen.findByText('Visible seulement par toi seul')).toBeVisible();
  });

  it('checks the visual before upload', async () => {
    const { user } = await openComposer();
    const input = await screen.findByLabelText('Visuel (facultatif)');
    await user.upload(input, file('a.gif', 'image/gif'));
    expect(await screen.findByText(/Format non pris en charge/)).toBeVisible();
    await user.upload(input, file('a.png', 'image/png', 5 * 1024 * 1024 + 1));
    expect(await screen.findByText('Image trop lourde : 5 Mo maximum.')).toBeVisible();
    await user.upload(input, file('v.mp4', 'video/mp4', 15 * 1024 * 1024 + 1));
    expect(await screen.findByText('Vidéo trop lourde : 15 Mo maximum.')).toBeVisible();
    vi.mocked(readVideoDuration).mockResolvedValueOnce(61_000);
    await user.upload(input, file('v.mp4', 'video/mp4'));
    expect(await screen.findByText('Vidéo trop longue : 60 secondes maximum.')).toBeVisible();
    await user.upload(input, file('ok.webp', 'image/webp'));
    expect(await screen.findByText(/ok\.webp/)).toBeVisible();
  });

  it('counts the caption, highlights hashtags as text and blocks over 300', async () => {
    const { user } = await openComposer();
    const box = await screen.findByLabelText('Légende (facultatif)');
    await user.click(box);
    await user.paste('Nouveau #LoFi <b>gras</b> #beat_2');
    expect(screen.getByText('33 / 300')).toBeVisible();
    const hl = screen.getByTestId('caption-highlight');
    expect(hl.querySelectorAll('span.text-accent')).toHaveLength(2);
    expect(hl.querySelector('b')).toBeNull();
    await user.clear(box);
    await user.click(box);
    await user.paste('x'.repeat(301));
    expect(screen.getByText(/301 \/ 300/)).toBeVisible();
    expect(screen.getByRole('button', { name: 'Publier' })).toBeDisabled();
  });

  it('submits multipart, polls until ready and opens /posts/:id', async () => {
    let body: FormData | null = null;
    let polls = 0;
    server.use(
      http.post('*/api/v1/posts', async ({ request }) => {
        body = await request.formData();
        return HttpResponse.json(post(), { status: 202 });
      }),
      http.get(`*/api/v1/posts/${POST_ID}`, () => {
        polls += 1;
        return HttpResponse.json(post({ status: polls >= 3 ? 'ready' : 'processing' }));
      }),
    );
    const { user, router } = await openComposer();
    await user.click(await screen.findByLabelText('Légende (facultatif)'));
    await user.paste('Hello #wave');
    await user.upload(screen.getByLabelText('Visuel (facultatif)'), file('c.png', 'image/png'));
    await user.click(screen.getByRole('button', { name: 'Publier' }));
    await waitFor(() => expect(router.state.location.pathname).toBe(`/posts/${POST_ID}`));
    expect(polls).toBeGreaterThanOrEqual(3);
    const sent = body as unknown as FormData;
    expect(sent.get('trackId')).toBe(BRUME);
    expect(sent.get('clipStartMs')).toBe('0');
    expect(sent.get('clipDurationMs')).toBe('30000');
    expect(sent.get('caption')).toBe('Hello #wave');
    expect((sent.get('visual') as File).name).toBe('c.png');
  });

  it('reports a failed post in French and lets the user retry', async () => {
    server.use(
      http.post('*/api/v1/posts', () => HttpResponse.json(post(), { status: 202 })),
      http.get(`*/api/v1/posts/${POST_ID}`, () =>
        HttpResponse.json(post({ status: 'failed', failureReason: 'invalid_media' })),
      ),
    );
    const { user } = await openComposer();
    await user.click(await screen.findByRole('button', { name: 'Publier' }));
    expect(
      await screen.findByText('Le visuel est illisible ou n’est pas un format pris en charge.'),
    ).toBeVisible();
    await user.click(screen.getByRole('button', { name: 'Réessayer' }));
    expect(screen.getByRole('button', { name: 'Publier' })).toBeEnabled();
  });

  it('stops after the timeout with a link to the profile', async () => {
    server.use(
      http.post('*/api/v1/posts', () => HttpResponse.json(post(), { status: 202 })),
      http.get(`*/api/v1/posts/${POST_ID}`, () => HttpResponse.json(post())),
    );
    const { user } = await openComposer();
    await user.click(await screen.findByRole('button', { name: 'Publier' }));
    expect(await screen.findByText('Toujours en cours')).toBeVisible();
    expect(screen.getByRole('link', { name: 'ton profil' })).toHaveAttribute('href', '/u/lea');
  });

  it.each([
    [404, 'not_found', 'Ce morceau n’est plus disponible.'],
    [409, 'track_not_ready', /encore en cours de traitement/],
    [413, 'payload_too_large', /Visuel trop lourd/],
    [415, 'unsupported_media_type', /Format du visuel non pris en charge/],
    [422, 'clips_not_allowed', 'Tu ne peux pas utiliser d’extrait de ce morceau.'],
    [422, 'clip_out_of_range', /dépasse la fin du morceau/],
    [429, 'rate_limited', /Trop de tentatives/],
  ] as const)('maps %i %s to a French message', async (status, code, text) => {
    server.use(
      http.post('*/api/v1/posts', () =>
        HttpResponse.json(
          { type: `urn:wave:problem:${code}`, title: code, status, code },
          { status, headers: { 'Content-Type': 'application/problem+json' } },
        ),
      ),
    );
    const { user } = await openComposer();
    await user.click(await screen.findByRole('button', { name: 'Publier' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(text);
    expect(screen.getByRole('button', { name: 'Publier' })).toBeEnabled();
  });
});
