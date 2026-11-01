import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '@/api/instance';
import { Toaster } from '@/components/ui/Toaster';
import { db } from '@/mocks/db';
import { USER_IDS } from '@/mocks/fixtures';
import { server } from '@/mocks/server';
import { ProfilePage } from '@/features/profile/ProfilePage';
import { SearchPage, SEARCH_DEBOUNCE_MS } from '@/features/search/SearchPage';
import { socialStore } from '@/stores/social';
import { loginAs, renderRoute } from '@/test/utils';
import { FollowListPage } from './FollowListPage';
import { LikeButton } from './LikeButton';

const serverError = () =>
  HttpResponse.json(
    { type: 'urn:wave:problem:internal_error', title: 'x', status: 500, code: 'internal_error' },
    { status: 500, headers: { 'Content-Type': 'application/problem+json' } },
  );

afterEach(() => {
  socialStore.getState().reset();
  vi.useRealTimers();
});

describe('likes (W-25)', () => {
  it('is optimistic, then confirmed by the server', async () => {
    await loginAs('lea@wave.local');
    server.use(
      http.put('*/api/v1/tracks/:id/like', async () => {
        await delay(200);
        return undefined;
      }),
    );
    const user = userEvent.setup();
    const t = await api.tracks.get('0192f3a2-0000-7000-8000-000000000001');
    renderRoute(
      <LikeButton
        kind="track"
        id={t.id}
        title={t.title}
        server={{ liked: t.likedByMe, likeCount: t.likeCount }}
        showCount
      />,
    );
    const btn = screen.getByRole('button', { name: /Ajouter « Brume sur le port »/ });
    expect(btn).toHaveAttribute('aria-pressed', 'false');
    await user.click(btn);
    expect(btn).toHaveAttribute('aria-pressed', 'true'); // before the server answers
    expect(btn).toHaveTextContent(String(t.likeCount + 1));
    await waitFor(() =>
      expect(
        db.state.trackLikes.some((l) => l.targetId === t.id && l.userId === USER_IDS.lea),
      ).toBe(true),
    );
  });

  it('rolls back and explains on failure', async () => {
    await loginAs('lea@wave.local');
    server.use(
      http.put('*/api/v1/playlists/:id/like', async () => {
        await delay(100);
        return serverError();
      }),
    );
    const user = userEvent.setup();
    renderRoute(
      <>
        <LikeButton
          kind="playlist"
          id="0192f3a3-0000-7000-8000-000000000001"
          title="Matins calmes"
          server={{ liked: false, likeCount: 3 }}
          showCount
        />
        <Toaster />
      </>,
    );
    const btn = screen.getByRole('button', { name: /Matins calmes/ });
    await user.click(btn);
    expect(btn).toHaveAttribute('aria-pressed', 'true');
    expect(
      await screen.findByText('Une erreur est survenue. Réessaie dans un instant.'),
    ).toBeInTheDocument();
    expect(btn).toHaveAttribute('aria-pressed', 'false');
    expect(btn).toHaveTextContent('3');
  });
});

describe('follow (W-25)', () => {
  it('profile: optimistic follow updates the count, rollback on error', async () => {
    await loginAs('nour@wave.local');
    const user = userEvent.setup();
    renderRoute(<ProfilePage />, { path: '/u/:username', url: '/u/kenji' });
    const btn = await screen.findByRole('button', { name: 'Suivre Kenji Sato' });
    expect(screen.getByRole('link', { name: /21 abonnés/ })).toBeInTheDocument();
    await user.click(btn);
    expect(screen.getByRole('button', { name: 'Ne plus suivre Kenji Sato' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    expect(screen.getByRole('link', { name: /22 abonnés/ })).toBeInTheDocument();

    server.use(
      http.delete('*/api/v1/users/:u/follow', async () => {
        await delay(50);
        return serverError();
      }),
    );
    await user.click(screen.getByRole('button', { name: 'Ne plus suivre Kenji Sato' }));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Ne plus suivre Kenji Sato' })).toBeEnabled(),
    );
    expect(screen.getByRole('link', { name: /22 abonnés/ })).toBeInTheDocument();
  });

  it('own profile has no follow button; followers list scrolls by pages', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<FollowListPage which="followers" />, {
      path: '/u/:username/followers',
      url: '/u/lea/followers',
    });
    const list = await screen.findByRole('list', { name: 'Abonnés' });
    expect(list.querySelectorAll('li')).toHaveLength(20);
    await user.click(screen.getByRole('button', { name: 'Charger plus' }));
    await waitFor(() => expect(list.querySelectorAll('li')).toHaveLength(40));
    await user.click(screen.getByRole('button', { name: 'Charger plus' }));
    await waitFor(() => expect(list.querySelectorAll('li')).toHaveLength(41));
    expect(screen.queryByRole('button', { name: 'Charger plus' })).toBeNull();
  });
});

describe('user search (W-28)', () => {
  function searchRequests() {
    const seen: { q: string; aborted: () => boolean }[] = [];
    server.events.on('request:start', ({ request }) => {
      const u = new URL(request.url);
      if (u.pathname.endsWith('/search'))
        seen.push({ q: u.searchParams.get('q') ?? '', aborted: () => request.signal.aborted });
    });
    return seen;
  }

  it('debounces, needs 2 chars, shows results with follow buttons shared with the profile cache', async () => {
    await loginAs('lea@wave.local');
    const seen = searchRequests();
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderRoute(<SearchPage />, { path: '/search' });
    const input = screen.getByRole('searchbox', { name: 'Rechercher une personne' });
    await user.type(input, 'k');
    await act(() => vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50));
    expect(seen).toHaveLength(0);
    expect(screen.getByText('Tape au moins 2 caractères.')).toBeInTheDocument();
    await user.type(input, 'enj');
    expect(seen).toHaveLength(0); // still within the debounce window
    await act(() => vi.advanceTimersByTimeAsync(SEARCH_DEBOUNCE_MS + 50));
    const results = await screen.findByRole('list', { name: 'Résultats pour « kenj »' });
    expect(seen.map((s) => s.q)).toEqual(['kenj']);
    expect(results).toHaveTextContent('Kenji Sato');
    expect(screen.getByRole('button', { name: 'Ne plus suivre Kenji Sato' })).toBeInTheDocument(); // Léa follows Kenji
  });

  it('cancels the previous request when the query changes', async () => {
    await loginAs('lea@wave.local');
    const seen = searchRequests();
    server.use(
      http.get('*/api/v1/search', async () => {
        await delay(400);
        return undefined;
      }),
    );
    const user = userEvent.setup();
    renderRoute(<SearchPage />, { path: '/search' });
    const input = screen.getByRole('searchbox', { name: 'Rechercher une personne' });
    await user.type(input, 'ca');
    await waitFor(() => expect(seen).toHaveLength(1), { timeout: 1000 });
    await user.type(input, 'm');
    await waitFor(() => expect(seen).toHaveLength(2), { timeout: 1000 });
    expect(seen[0]!.aborted()).toBe(true);
    expect(
      await screen.findByRole('list', { name: 'Résultats pour « cam »' }, { timeout: 2000 }),
    ).toHaveTextContent('Camille');
  });

  it('shows empty and rate-limited states', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<SearchPage />, { path: '/search', url: '/search?q=zzzz' });
    expect(
      await screen.findByText('Personne ne correspond à « zzzz »', {}, { timeout: 2000 }),
    ).toBeInTheDocument();
    server.use(
      http.get('*/api/v1/search', () =>
        HttpResponse.json(
          { type: 'urn:wave:problem:rate_limited', title: 'x', status: 429, code: 'rate_limited' },
          {
            status: 429,
            headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '30' },
          },
        ),
      ),
    );
    await user.type(screen.getByRole('searchbox'), 'y');
    expect(
      await screen.findByText(/beaucoup de recherches.*Réessaie dans 30 s/, {}, { timeout: 2000 }),
    ).toBeInTheDocument();
  });
});
