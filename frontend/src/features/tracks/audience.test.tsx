import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { api } from '@/api/instance';
import type { Visibility } from '@/api/types';
import { db } from '@/mocks/db';
import { ProfilePage } from '@/features/profile/ProfilePage';
import { loginAs, renderRoute } from '@/test/utils';
import { AUDIENCE_CHANGE_NOTE } from './audience';
import { TrackPage } from './TrackPage';
import { UploadPage } from './UploadPage';

const DEMO = '0192f3a2-0000-7000-8000-000000000004'; // Léa, private
const BRUME = '0192f3a2-0000-7000-8000-000000000001'; // Léa, public
const KENJI_FOLLOWERS = '0192f3a2-0000-7000-8000-00000000000b';

const LABEL: Record<Visibility, string> = {
  public: 'Public',
  followers: 'Abonnés',
  close_friends: 'Amis proches',
  private: 'Moi seul',
};
const ALL = Object.keys(LABEL) as Visibility[];

describe('audience selector (W-48, FB-18)', () => {
  it.each(ALL)('upload sends visibility=%s', async (value) => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<UploadPage />, { url: '/?artist=nuit-blanche' });
    await user.upload(
      await waitFor(() => screen.getByLabelText(/Choisis tes morceaux/)),
      new File([new Uint8Array(512)], 'nuit.mp3', { type: 'audio/mpeg' }),
    );
    const group = await screen.findByRole('radiogroup', { name: 'Qui peut écouter ce morceau ?' });
    expect(within(group).getAllByRole('radio')).toHaveLength(4);
    expect(within(group).getByRole('radio', { name: 'Abonnés' })).toHaveAccessibleDescription(
      'Toute personne qui te suit',
    );
    await user.click(within(group).getByRole('radio', { name: LABEL[value] }));
    await waitFor(() =>
      expect(screen.getAllByRole('option', { name: 'Ambient' }).length).toBeGreaterThan(0),
    );
    await user.selectOptions(screen.getByLabelText('Genre de nuit.mp3'), 'ambient');
    await user.click(screen.getByRole('button', { name: 'Envoyer le morceau' }));
    await waitFor(() =>
      expect(db.state.tracks.find((t) => t.title === 'nuit')?.visibility).toBe(value),
    );
  });

  it.each(['public', 'followers', 'close_friends'] as const)(
    'edit form PATCHes visibility=%s, with the note about posts',
    async (value) => {
      await loginAs('lea@wave.local');
      const user = userEvent.setup();
      renderRoute(<TrackPage />, { path: '/t/:id', url: `/t/${DEMO}` });
      await user.click(await screen.findByRole('button', { name: 'Modifier' }));
      expect(screen.getByRole('radio', { name: 'Moi seul' })).toBeChecked();
      expect(screen.queryByText(AUDIENCE_CHANGE_NOTE)).toBeNull();
      await user.click(screen.getByRole('radio', { name: LABEL[value] }));
      expect(screen.getByText(AUDIENCE_CHANGE_NOTE)).toBeInTheDocument();
      await user.click(screen.getByRole('button', { name: 'Enregistrer' }));
      await waitFor(() =>
        expect(db.state.tracks.find((t) => t.id === DEMO)?.visibility).toBe(value),
      );
    },
  );

  it('close friends: member count and link to the list', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<TrackPage />, { path: '/t/:id', url: `/t/${BRUME}` });
    await user.click(await screen.findByRole('button', { name: 'Modifier' }));
    expect(screen.queryByRole('link', { name: 'Gérer mes amis proches' })).toBeNull();
    await user.click(screen.getByRole('radio', { name: 'Amis proches' }));
    expect(await screen.findByTestId('close-friend-count')).toHaveTextContent('1 ami proche');
    expect(screen.getByRole('link', { name: 'Gérer mes amis proches' })).toHaveAttribute(
      'href',
      '/settings/close-friends',
    );
  });
});

describe('audience badge (W-48)', () => {
  it.each([
    ['public', null],
    ['followers', 'Abonnés'],
    ['close_friends', 'Amis proches'],
    ['private', 'Moi seul'],
  ] as const)('own track %s → %s', async (value, label) => {
    db.state.tracks.find((t) => t.id === BRUME)!.visibility = value;
    await loginAs('lea@wave.local');
    renderRoute(<TrackPage />, { path: '/t/:id', url: `/t/${BRUME}` });
    await screen.findByRole('heading', { name: 'Brume sur le port' });
    const badge = screen.queryByTestId('audience-badge');
    if (label) expect(badge).toHaveTextContent(label);
    else expect(badge).toBeNull();
  });

  it('someone else’s restricted track: visibility null → « Partagé avec toi »', async () => {
    await loginAs('lea@wave.local'); // follows Kenji
    expect((await api.tracks.get(KENJI_FOLLOWERS)).visibility).toBeNull();
    renderRoute(<ProfilePage />, { path: '/u/:username', url: '/u/kenji' });
    const row = (await screen.findByText('Pour mes abonnés')).closest('li')!;
    // TrackList renders the badges twice (mobile and desktop layouts).
    for (const b of within(row).getAllByTestId('audience-badge'))
      expect(b).toHaveTextContent('Partagé avec toi');
    const pub = screen.getByText('Café crème').closest('li')!; // public: no badge
    expect(within(pub).queryByTestId('audience-badge')).toBeNull();
  });

  it('outside the audience the track is not listed', async () => {
    await loginAs('nour@wave.local'); // does not follow Kenji
    renderRoute(<ProfilePage />, { path: '/u/:username', url: '/u/kenji' });
    await screen.findByText('Café crème');
    expect(screen.queryByText('Pour mes abonnés')).toBeNull();
  });
});
