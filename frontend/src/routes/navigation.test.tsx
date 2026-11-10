import { QueryClientProvider } from '@tanstack/react-query';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createQueryClient } from '@/api/queryClient';
import { keys } from '@/api/queries/keys';
import type { Track } from '@/api/types';
import { playerStore } from '@/stores/player';
import { sessionStore } from '@/stores/session';
import { loginAs } from '@/test/utils';
import { activeTab, isFeedPath, type TabId } from './navigation';
import { routes } from './router';

const ME = 'lea';

describe('activeTab (route map of W-38)', () => {
  const table: [string, TabId | null][] = [
    ['/', 'for-you'],
    ['/following', 'for-you'],
    ['/posts/0192aaaa-0000-7000-8000-000000000001', null],
    ['/discover', 'discover'],
    ['/search', 'discover'],
    ['/a/nour-beats', 'discover'],
    ['/t/0192aaaa-0000-7000-8000-000000000001', 'discover'],
    ['/u/kenji', 'discover'],
    ['/u/kenji/followers', 'discover'],
    ['/library', 'library'],
    ['/p/0192aaaa-0000-7000-8000-000000000001', 'library'],
    ['/u/lea', 'profile'],
    ['/u/Lea/following', 'profile'],
    ['/settings', 'profile'],
    ['/me/artists', 'profile'],
    ['/me/artists/new', 'profile'],
    ['/posts/new', 'create'],
    ['/upload', 'create'],
    ['/nowhere', null],
  ];
  it.each(table)('%s → %s', (path, tab) => {
    expect(activeTab(path, ME)).toBe(tab);
  });

  it('hides the mini player on feed screens only', () => {
    expect(['/', '/following', '/posts/abc'].every(isFeedPath)).toBe(true);
    expect(['/posts/new', '/discover', '/library', '/following/x'].some(isFeedPath)).toBe(false);
  });
});

const track = {
  id: '0192aaaa-0000-7000-8000-0000000000aa',
  title: 'Néons',
  status: 'ready',
  durationMs: 180_000,
  coverUrl: null,
  likedByMe: false,
  likeCount: 0,
  artist: { id: 'x', name: 'Nour', slug: 'nour', avatarUrl: null },
} as unknown as Track;

function renderApp(url: string) {
  const queryClient = createQueryClient();
  queryClient.setDefaultOptions({
    queries: { retry: false, staleTime: Infinity, refetchOnWindowFocus: false },
  });
  const user = sessionStore.getState().user;
  if (user) queryClient.setQueryData(keys.me(), user);
  const router = createMemoryRouter(routes, { initialEntries: [url] });
  render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return router;
}

/** happy-dom answers media queries for a 1024 px window: force the viewport per suite. */
function setViewport(desktop: boolean) {
  vi.spyOn(window, 'matchMedia').mockImplementation(
    (query: string) =>
      ({
        matches: desktop,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
      }) as unknown as MediaQueryList,
  );
}

const mainNav = () => screen.getByRole('navigation', { name: 'Navigation principale' });

afterEach(() => {
  vi.restoreAllMocks();
  playerStore.setState({ queue: [], index: -1 });
});

describe('navigation shell (mobile)', () => {
  beforeEach(async () => {
    setViewport(false);
    await loginAs('lea@wave.local');
  });

  it('shows the 5 tabs in order, with Pour toi active on /', async () => {
    renderApp('/');
    expect(await screen.findByText('Le fil arrive bientôt')).toBeInTheDocument();
    const items = within(mainNav()).getAllByRole('listitem');
    expect(items.map((li) => li.textContent)).toEqual([
      'Pour toi',
      'Découvrir',
      'Créer',
      'Bibliothèque',
      'Profil',
    ]);
    expect(within(mainNav()).getByRole('link', { name: 'Pour toi' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    expect(within(mainNav()).getByRole('link', { name: 'Profil' })).toHaveAttribute(
      'href',
      `/u/${ME}`,
    );
  });

  it('opens the + sheet with both links, closes on Escape and gives focus back to +', async () => {
    const user = userEvent.setup();
    const router = renderApp('/library');
    const plus = await within(mainNav()).findByRole('button', { name: 'Créer' });
    await user.click(plus);
    const sheet = screen.getByRole('dialog', { name: 'Créer' });
    expect(within(sheet).getByRole('link', { name: /Publier un extrait/ })).toHaveAttribute(
      'href',
      '/posts/new',
    );
    expect(within(sheet).getByRole('link', { name: /Importer des morceaux/ })).toHaveAttribute(
      'href',
      '/upload',
    );
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Créer' })).not.toBeInTheDocument();
    expect(plus).toHaveFocus();

    await user.click(plus);
    await user.click(
      within(screen.getByRole('dialog', { name: 'Créer' })).getByRole('link', {
        name: /Publier un extrait/,
      }),
    );
    await waitFor(() => expect(router.state.location.pathname).toBe('/posts/new'));
    expect(screen.queryByRole('dialog', { name: 'Créer' })).not.toBeInTheDocument();
    expect(within(mainNav()).getByRole('button', { name: 'Créer' })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });

  it('redirects /search?q=ab to /discover?q=ab', async () => {
    const router = renderApp('/search?q=ab');
    await waitFor(() => expect(router.state.location.pathname).toBe('/discover'));
    expect(router.state.location.search).toBe('?q=ab');
    expect(within(mainNav()).getByRole('link', { name: 'Découvrir' })).toHaveAttribute(
      'aria-current',
      'page',
    );
  });

  it('keeps the tab bar usable over the full player, and a tab closes it', async () => {
    playerStore.setState({ queue: [track], index: 0 });
    const user = userEvent.setup();
    const router = renderApp('/library');
    await user.click(await screen.findByRole('button', { name: /Ouvrir le lecteur/ }));
    expect(screen.getByRole('dialog', { name: 'Lecteur en plein écran' })).toBeInTheDocument();
    expect(mainNav()).toBeInTheDocument();
    await user.click(within(mainNav()).getByRole('link', { name: 'Découvrir' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/discover'));
    expect(
      screen.queryByRole('dialog', { name: 'Lecteur en plein écran' }),
    ).not.toBeInTheDocument();
  });

  it('hides the mini player on / but shows it elsewhere', async () => {
    playerStore.setState({ queue: [track], index: 0 });
    renderApp('/');
    await screen.findByText('Le fil arrive bientôt');
    expect(screen.queryByRole('region', { name: 'Lecteur' })).not.toBeInTheDocument();
    expect(mainNav()).toBeInTheDocument();
  });

  it('sends a signed-in visitor of /login to a safe `next`, or to /', async () => {
    let router = renderApp('/login?next=%2Flibrary');
    await waitFor(() => expect(router.state.location.pathname).toBe('/library'));
    router.dispose();
    router = renderApp('/login?next=https%3A%2F%2Fevil.example');
    await waitFor(() => expect(router.state.location.pathname).toBe('/'));
  });
});

describe('navigation shell (desktop)', () => {
  beforeEach(async () => {
    setViewport(true);
    await loginAs('lea@wave.local');
  });

  it('shows the same 5 entries in the rail and + opens a popover', async () => {
    const user = userEvent.setup();
    renderApp('/library');
    const nav = await screen.findByRole('navigation', { name: 'Navigation principale' });
    expect([...nav.querySelectorAll('a, button')].map((el) => el.textContent)).toEqual([
      'Pour toi',
      'Découvrir',
      'Créer',
      'Bibliothèque',
      'Profil',
    ]);
    expect(within(nav).getByRole('link', { name: 'Bibliothèque' })).toHaveAttribute(
      'aria-current',
      'page',
    );
    const plus = within(nav).getByRole('button', { name: 'Créer' });
    await user.click(plus);
    expect(screen.getByRole('dialog', { name: 'Créer' })).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('dialog', { name: 'Créer' })).not.toBeInTheDocument();
    expect(plus).toHaveFocus();
  });
});

describe('navigation shell (anonymous)', () => {
  it('sends an anonymous visitor of / to /login', async () => {
    const router = renderApp('/');
    await waitFor(() => expect(router.state.location.pathname).toBe('/login'));
    expect(screen.queryByRole('navigation', { name: 'Navigation principale' })).toBeNull();
  });
});
