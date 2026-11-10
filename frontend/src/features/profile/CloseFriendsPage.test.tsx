import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { Toaster } from '@/components/ui/Toaster';
import { db } from '@/mocks/db';
import { USER_IDS } from '@/mocks/fixtures';
import { server } from '@/mocks/server';
import { loginAs, renderRoute } from '@/test/utils';
import { CloseFriendsPage } from './CloseFriendsPage';

const problem = (status: number, code: string) =>
  HttpResponse.json(
    { type: `urn:wave:problem:${code}`, title: 'x', status, code },
    { status, headers: { 'Content-Type': 'application/problem+json' } },
  );

function renderPage() {
  return renderRoute(
    <>
      <CloseFriendsPage />
      <Toaster />
    </>,
    { path: '/settings/close-friends' },
  );
}

const rows = () =>
  within(screen.getByRole('list', { name: 'Tes abonnés' })).getAllByRole('listitem');
const nourSwitch = () => screen.getByRole('switch', { name: 'Nour dans mes amis proches' });

describe('close friends screen (W-48, FB-18)', () => {
  it('lists followers with their flag, infinite scroll', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('list', { name: 'Tes abonnés' });
    expect(rows()).toHaveLength(20);
    expect(nourSwitch()).toHaveAttribute('aria-checked', 'true');
    await user.click(screen.getByRole('button', { name: 'Charger plus' }));
    await waitFor(() => expect(rows()).toHaveLength(40));
  });

  it('toggles optimistically, then the server confirms', async () => {
    await loginAs('lea@wave.local');
    server.use(
      http.delete('*/api/v1/me/close-friends/:username', async () => {
        await delay(150);
        return undefined;
      }),
    );
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('list', { name: 'Tes abonnés' });
    await user.click(nourSwitch());
    expect(nourSwitch()).toHaveAttribute('aria-checked', 'false'); // before the answer
    await waitFor(() =>
      expect(db.state.closeFriends.some((c) => c.friendId === USER_IDS.nour)).toBe(false),
    );
    expect(nourSwitch()).toHaveAttribute('aria-checked', 'false');
  });

  it('rolls back on failure', async () => {
    await loginAs('lea@wave.local');
    server.use(
      http.delete('*/api/v1/me/close-friends/:username', async () => {
        await delay(50);
        return problem(500, 'internal_error');
      }),
    );
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('list', { name: 'Tes abonnés' });
    await user.click(nourSwitch());
    expect(nourSwitch()).toHaveAttribute('aria-checked', 'false');
    await waitFor(() => expect(nourSwitch()).toHaveAttribute('aria-checked', 'true'));
    expect(await screen.findByText(/Une erreur est survenue/)).toBeInTheDocument();
  });

  it('422 not_a_follower: explains and removes the row', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole('list', { name: 'Tes abonnés' });
    const first = rows()[1]!; // rows[0] is Nour, already a member
    const name = within(first).getByRole('switch').getAttribute('aria-label')!;
    // The follower unfollowed after the list was loaded.
    const fan = within(first).getByText(/^@/).textContent!.slice(1);
    const fanId = db.state.users.find((u) => u.username === fan)!.id;
    db.state.follows = db.state.follows.filter((f) => f.followerId !== fanId);
    await user.click(screen.getByRole('switch', { name }));
    expect(await screen.findByText('Cette personne ne te suit plus.')).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('switch', { name })).toBeNull());
    expect(db.state.closeFriends.some((c) => c.friendId === fanId)).toBe(false);
  });

  it('empty state when nobody follows me', async () => {
    await loginAs('nour@wave.local');
    renderPage();
    expect(await screen.findByText('Personne ne te suit encore')).toBeInTheDocument();
  });
});
