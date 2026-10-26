import { act, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockConfig } from '@/mocks/db';
import { server } from '@/mocks/server';
import { loginAs, renderRoute } from '@/test/utils';
import { UploadPage } from './UploadPage';

function audioFile(name: string, type = 'audio/mpeg', size = 4096) {
  return new File([new Uint8Array(size)], name, { type });
}
const picker = () => screen.getByLabelText(/Choisis tes morceaux|Ajouter d’autres fichiers/);

async function waitForGenres() {
  await waitFor(() =>
    expect(screen.getAllByRole('option', { name: 'Ambient' }).length).toBeGreaterThan(0),
  );
}

describe('upload page (W-62/W-63)', () => {
  afterEach(() => {
    vi.useRealTimers();
    mockConfig.trackProcessingMs = 8_000;
  });

  it('is disabled with an explanation while the email is unverified', async () => {
    await loginAs('nour@wave.local');
    renderRoute(<UploadPage />);
    expect(
      await screen.findByText('Confirmez votre adresse e-mail pour importer des morceaux.'),
    ).toBeInTheDocument();
    expect(picker()).toBeDisabled();
  });

  it('accepts MP4 up to 200 MB, rejects others client-side with a French message', async () => {
    await loginAs('lea@wave.local');
    const user = userEvent.setup({ applyAccept: false });
    renderRoute(<UploadPage />);
    const mp4 = audioFile('live.mp4', 'video/mp4');
    Object.defineProperty(mp4, 'size', { value: 150_000_000 });
    const bigMp3 = audioFile('gros.mp3');
    Object.defineProperty(bigMp3, 'size', { value: 31_457_281 });
    const bigMp4 = audioFile('film.mp4', 'video/mp4');
    Object.defineProperty(bigMp4, 'size', { value: 209_715_201 });
    await user.upload(await waitFor(picker), [
      mp4,
      bigMp3,
      bigMp4,
      new File(['x'], 'notes.wav', { type: 'audio/wav' }),
    ]);
    const rows = screen.getAllByRole('listitem');
    expect(rows).toHaveLength(4);
    expect(within(rows[0]!).getByLabelText('Titre de live.mp4')).toHaveValue('live');
    expect(
      within(rows[1]!).getByText('Fichier trop lourd : 30 Mo maximum pour un MP3 ou AAC/M4A.'),
    ).toBeInTheDocument();
    expect(
      within(rows[2]!).getByText('Fichier trop lourd : 200 Mo maximum pour un MP4.'),
    ).toBeInTheDocument();
    expect(
      within(rows[3]!).getByText(
        /Format non pris en charge : choisissez un fichier MP3, AAC\/M4A ou MP4/,
      ),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Envoyer le morceau' })).toBeInTheDocument();
  });

  it('sends several files with at most 3 in parallel; one failure does not stop the others', async () => {
    await loginAs('lea@wave.local');
    const stats = { inFlight: 0, max: 0, bSlots: 0 };
    server.use(
      http.post('*/mock-storage/wave-private', async ({ request }) => {
        const file = (await request.clone().formData()).get('file') as File;
        stats.inFlight++;
        stats.max = Math.max(stats.max, stats.inFlight);
        await delay(40);
        stats.inFlight--;
        if (file.name === 'b.mp3')
          return new HttpResponse('<Error><Code>AccessDenied</Code></Error>', { status: 403 });
        return undefined;
      }),
      // The automatic retry with a new slot fails too for this file (rate limited).
      http.post('*/api/v1/tracks/uploads', async ({ request }) => {
        const body = (await request.clone().json()) as { filename: string };
        if (body.filename === 'b.mp3' && ++stats.bSlots > 1)
          return HttpResponse.json(
            {
              type: 'urn:wave:problem:rate_limited',
              title: 'x',
              status: 429,
              code: 'rate_limited',
            },
            {
              status: 429,
              headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '120' },
            },
          );
        return undefined;
      }),
    );
    const user = userEvent.setup();
    renderRoute(<UploadPage />, { url: '/?artist=nuit-blanche' });
    await user.upload(
      await waitFor(picker),
      ['a', 'b', 'c', 'd', 'e'].map((n) => audioFile(`${n}.mp3`)),
    );
    await waitForGenres();
    expect(screen.getByLabelText('Artiste')).toHaveDisplayValue('Nuit Blanche');
    await user.selectOptions(screen.getByLabelText('Genre pour tous les fichiers'), 'ambient');
    await user.click(screen.getByRole('button', { name: 'Envoyer les 5 morceaux' }));

    await waitFor(
      () =>
        expect(screen.getAllByText('Fichier reçu, traitement de l’audio en cours…')).toHaveLength(
          4,
        ),
      { timeout: 4000 },
    );
    expect(stats.max).toBeLessThanOrEqual(3);
    expect(stats.max).toBeGreaterThan(1);
    const failed = screen.getByText(/Réessayez dans 2 min/).closest('li')!;
    expect(within(failed).getByText('b.mp3', { exact: false })).toBeInTheDocument();
    expect(within(failed).getByRole('button', { name: 'Réessayer' })).toBeInTheDocument();
  });

  it('follows processing until a readable failure reason', async () => {
    mockConfig.trackProcessingMs = 0;
    await loginAs('lea@wave.local');
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderRoute(<UploadPage />);
    await user.upload(await waitFor(picker), audioFile('demo-fail.mp3'));
    await waitForGenres();
    await user.selectOptions(screen.getByLabelText('Genre de demo-fail.mp3'), 'ambient');
    // Léa owns two artists: an explicit choice is required.
    expect(screen.getByRole('button', { name: 'Envoyer le morceau' })).toBeDisabled();
    await user.selectOptions(screen.getByLabelText('Artiste'), 'Léa Martin');
    await user.click(screen.getByRole('button', { name: 'Envoyer le morceau' }));
    expect(
      await screen.findByText('Fichier reçu, traitement de l’audio en cours…'),
    ).toBeInTheDocument();
    await act(() => vi.advanceTimersByTimeAsync(3_100));
    expect(
      await screen.findByText('Échec : Le fichier n’est pas un audio valide.'),
    ).toBeInTheDocument();
  });
});
