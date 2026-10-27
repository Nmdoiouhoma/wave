import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { loginAs, renderRoute } from '@/test/utils';
import { ALLOW_CLIPS_HELP, ALLOW_CLIPS_LABEL } from './AllowClipsField';
import { TrackPage } from './TrackPage';
import { UploadPage } from './UploadPage';

const clipsSwitch = () => screen.queryByRole('switch', { name: ALLOW_CLIPS_LABEL });

describe('allowClips only for public tracks (FB-09)', () => {
  it('uploader: hidden when private, value kept and still sent', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<UploadPage />, { url: '/?artist=nuit-blanche' });
    await user.upload(
      await waitFor(() => screen.getByLabelText(/Choisis tes morceaux/)),
      new File([new Uint8Array(512)], 'nuit.mp3', { type: 'audio/mpeg' }),
    );
    const toggle = await waitFor(() => clipsSwitch()!);
    expect(toggle).toHaveAccessibleDescription(ALLOW_CLIPS_HELP);
    expect(toggle).toHaveAttribute('aria-checked', 'true');
    await user.click(toggle); // opt out while public
    await user.click(screen.getByText('Privé'));
    expect(clipsSwitch()).toBeNull(); // hidden (aria-hidden + inert), not removed from state
    await user.click(screen.getByText('Public'));
    expect(clipsSwitch()).toHaveAttribute('aria-checked', 'false'); // previous choice restored
    await user.click(screen.getByText('Privé'));

    await waitFor(() =>
      expect(screen.getAllByRole('option', { name: 'Ambient' }).length).toBeGreaterThan(0),
    );
    await user.selectOptions(screen.getByLabelText('Genre pour tous les fichiers'), 'ambient');
    await user.click(screen.getByRole('button', { name: 'Envoyer le morceau' }));
    await waitFor(() => expect(db.state.tracks.some((t) => t.title === 'nuit')).toBe(true));
    expect(db.state.tracks.find((t) => t.title === 'nuit')).toMatchObject({
      visibility: 'private',
      allowClips: false,
    });
  });

  it('edit form: same rule on a private track', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<TrackPage />, { path: '/t/:id', url: '/t/0192f3a2-0000-7000-8000-000000000004' });
    await user.click(await screen.findByRole('button', { name: 'Modifier' }));
    expect(clipsSwitch()).toBeNull();
    await user.selectOptions(screen.getByLabelText('Visibilité'), 'public');
    expect(clipsSwitch()).toHaveAttribute('aria-checked', 'true');
  });
});
