import { act, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { mockConfig } from '@/mocks/db';
import { loginAs, renderRoute } from '@/test/utils';
import { UploadPage } from './UploadPage';

function audioFile(name: string, size = 4096) {
  return new File([new Uint8Array(size)], name, { type: 'audio/mpeg' });
}

describe('upload', () => {
  afterEach(() => {
    vi.useRealTimers();
    mockConfig.trackProcessingMs = 8_000;
  });

  it('is disabled with an explanation while the email is unverified', async () => {
    await loginAs('nour@wave.local');
    renderRoute(<UploadPage />);
    expect(
      await screen.findByText('Confirmez votre adresse e-mail pour importer des titres.'),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Importer' })).toBeDisabled();
  });

  it('rejects non-audio and oversized files client-side', async () => {
    await loginAs('lea@wave.local');
    // The browser `accept` filter is advisory (drag and drop bypasses it): check our own guard.
    const user = userEvent.setup({ applyAccept: false });
    renderRoute(<UploadPage />);
    const input = screen.getByLabelText(/Choisir un fichier audio/);
    await user.upload(input, new File(['x'], 'notes.txt', { type: 'text/plain' }));
    expect(screen.getByText(/Format non pris en charge/)).toBeInTheDocument();
    const big = audioFile('gros.mp3');
    Object.defineProperty(big, 'size', { value: 31_457_281 });
    await user.upload(input, big);
    expect(screen.getByText('Fichier trop lourd : 30 Mo maximum.')).toBeInTheDocument();
  });

  it('uploads, then polls until the track fails with a readable reason', async () => {
    mockConfig.trackProcessingMs = 0;
    await loginAs('lea@wave.local');
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderRoute(<UploadPage />);
    await user.upload(
      screen.getByLabelText(/Choisir un fichier audio/),
      audioFile('demo-fail.mp3'),
    );
    expect(screen.getByLabelText('Titre')).toHaveValue('demo fail');
    await waitFor(() =>
      expect(screen.getByRole('option', { name: 'Ambient' })).toBeInTheDocument(),
    );
    await user.selectOptions(screen.getByLabelText('Genre'), 'ambient');
    // Léa owns two artists: an explicit choice is required.
    expect(screen.getByRole('button', { name: 'Importer' })).toBeDisabled();
    await user.selectOptions(screen.getByLabelText('Artiste'), 'Nuit Blanche');
    await user.click(screen.getByRole('button', { name: 'Importer' }));

    expect(await screen.findByText(/Nous vérifions et préparons l’audio/)).toBeInTheDocument();
    await act(() => vi.advanceTimersByTimeAsync(3_100));
    expect(await screen.findByText('Le fichier n’est pas un audio valide.')).toBeInTheDocument();
  });

  it('shows the ready state with a play action', async () => {
    mockConfig.trackProcessingMs = 0;
    await loginAs('lea@wave.local');
    vi.useFakeTimers({ shouldAdvanceTime: true });
    const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
    renderRoute(<UploadPage />);
    await user.upload(screen.getByLabelText(/Choisir un fichier audio/), audioFile('aurore.mp3'));
    await waitFor(() =>
      expect(screen.getByRole('option', { name: 'Ambient' })).toBeInTheDocument(),
    );
    await user.selectOptions(screen.getByLabelText('Genre'), 'ambient');
    await user.selectOptions(screen.getByLabelText('Artiste'), 'Léa Martin');
    await user.click(screen.getByRole('button', { name: 'Importer' }));
    await screen.findByText(/Nous vérifions/);
    await act(() => vi.advanceTimersByTimeAsync(3_100));
    expect(await screen.findByText(/Votre titre est prêt/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Écouter' })).toBeEnabled();
  });
});
