import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { loginAs, renderRoute } from '@/test/utils';
import { ArtistPage } from './ArtistPage';
import { CreateArtistPage } from './CreateArtistPage';

const mp3 = (name: string) => new File([new Uint8Array(1024)], name, { type: 'audio/mpeg' });

describe('add tracks to an artist (W-63)', () => {
  it('wizard: step 1 creates the artist, step 2 uploads under it, the step is skippable', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    const { router } = renderRoute(<CreateArtistPage />, { path: '/me/artists/new' });
    await user.type(screen.getByLabelText('Nom de l’artiste'), 'Les Marées');
    await user.click(screen.getByRole('button', { name: 'Continuer : ajouter des morceaux' }));

    expect(await screen.findByRole('heading', { name: 'Ajoute tes morceaux' })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Passer cette étape' })).toHaveAttribute(
      'href',
      '/a/les-marees',
    );
    await user.upload(screen.getByLabelText(/Choisis tes morceaux/), [
      mp3('vague.mp3'),
      mp3('ressac.mp3'),
    ]);
    expect(screen.getByText('Publié sous', { exact: false })).toHaveTextContent('Les Marées');
    await waitFor(() =>
      expect(screen.getAllByRole('option', { name: 'Ambient' }).length).toBeGreaterThan(0),
    );
    await user.selectOptions(screen.getByLabelText('Genre pour tous les fichiers'), 'ambient');
    await user.click(screen.getByRole('button', { name: 'Envoyer les 2 morceaux' }));

    await waitFor(() =>
      expect(screen.getAllByText('Fichier reçu, traitement de l’audio en cours…')).toHaveLength(2),
    );
    const artist = db.state.artists.find((a) => a.slug === 'les-marees')!;
    expect(
      db.state.tracks
        .filter((t) => t.artistId === artist.id)
        .map((t) => t.title)
        .sort(),
    ).toEqual(['ressac', 'vague']);
    await user.click(screen.getByRole('link', { name: 'Voir la page de Les Marées' }));
    expect(router.state.location.pathname).toBe('/a/les-marees');
  });

  it('artist page: owner gets "Ajouter des morceaux" with the artist preselected', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup();
    renderRoute(<ArtistPage />, { path: '/a/:slug', url: '/a/nuit-blanche' });
    await user.click(await screen.findByRole('button', { name: 'Ajouter des morceaux' }));
    expect(
      screen.getByRole('heading', { name: 'Ajouter des morceaux à Nuit Blanche' }),
    ).toBeInTheDocument();
    await user.upload(screen.getByLabelText(/Choisis tes morceaux/), mp3('nuit.mp3'));
    expect(screen.queryByLabelText('Artiste')).not.toBeInTheDocument();
    expect(screen.getByText('Publié sous', { exact: false })).toHaveTextContent('Nuit Blanche');
  });

  it('empty artist page invites the owner to add tracks, not visitors', async () => {
    await loginAs('lea@wave.local');
    db.state.artists.push({
      ...db.state.artists[0]!,
      id: '0192f3a1-0000-7000-8000-0000000000ff',
      slug: 'vide',
      name: 'Vide',
    });
    const owner = renderRoute(<ArtistPage />, { path: '/a/:slug', url: '/a/vide' });
    expect(await screen.findByText('Vide n’a pas encore de morceau')).toBeInTheDocument();
    owner.unmount();
    await loginAs('kenji@wave.local');
    renderRoute(<ArtistPage />, { path: '/a/:slug', url: '/a/vide' });
    expect(await screen.findByText('Aucun titre pour l’instant')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Ajouter des morceaux' })).not.toBeInTheDocument();
  });
});
