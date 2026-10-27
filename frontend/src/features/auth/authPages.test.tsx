import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { server } from '@/mocks/server';
import { loginAs, renderRoute } from '@/test/utils';
import { sessionStore } from '@/stores/session';
import { ForgotPasswordPage, RESET_REQUESTED_MESSAGE } from './ForgotPasswordPage';
import { LoginPage } from './LoginPage';
import { RegisterPage } from './RegisterPage';
import { ResetPasswordPage } from './ResetPasswordPage';
import { VerifyEmailBanner } from './VerifyEmailBanner';
import { VerifyEmailPage } from './VerifyEmailPage';

const problemJson = (status: number, body: object, headers: Record<string, string> = {}) =>
  HttpResponse.json(body, {
    status,
    headers: { 'Content-Type': 'application/problem+json', ...headers },
  });

describe('register', () => {
  it('maps business conflicts and server violations onto fields', async () => {
    const user = userEvent.setup();
    renderRoute(<RegisterPage />);
    await user.type(screen.getByLabelText('Adresse e-mail'), 'new@wave.local');
    await user.type(screen.getByLabelText('Nom d’utilisateur'), 'lea');
    await user.type(screen.getByLabelText('Nom affiché'), 'Nouvelle');
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }));
    expect(await screen.findByText('Ce nom d’utilisateur est déjà pris.')).toBeInTheDocument();
    expect(screen.getByLabelText('Nom d’utilisateur')).toHaveAttribute('aria-invalid', 'true');

    server.use(
      http.post('*/api/v1/auth/register', () =>
        problemJson(422, {
          type: 'urn:wave:problem:validation_failed',
          title: 'Validation failed',
          status: 422,
          code: 'validation_failed',
          violations: [
            { propertyPath: 'displayName', message: 'Nom refusé par le serveur.', code: null },
          ],
        }),
      ),
    );
    await user.clear(screen.getByLabelText('Nom d’utilisateur'));
    await user.type(screen.getByLabelText('Nom d’utilisateur'), 'nouvelle');
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }));
    expect(await screen.findByText('Nom refusé par le serveur.')).toBeInTheDocument();
  });

  it('opens a session on success', async () => {
    const user = userEvent.setup();
    const { router } = renderRoute(<RegisterPage />, { path: '/register' });
    await user.type(screen.getByLabelText('Adresse e-mail'), 'zoe@wave.local');
    await user.type(screen.getByLabelText('Nom d’utilisateur'), 'Zoe');
    await user.type(screen.getByLabelText('Nom affiché'), 'Zoé');
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/'));
    expect(sessionStore.getState().user).toMatchObject({ username: 'zoe', emailVerified: false });
    expect(db.state.outbox[0]?.link).toContain('/verify-email?token=');
  });
});

describe('login', () => {
  it('shows a generic error on bad credentials and redirects to `next` on success', async () => {
    const user = userEvent.setup();
    const { router } = renderRoute(<LoginPage />, {
      path: '/login',
      url: '/login?next=%2Fu%2Fkenji',
    });
    await user.type(screen.getByLabelText('Adresse e-mail'), 'lea@wave.local');
    await user.type(screen.getByLabelText('Mot de passe'), 'mauvais-mot-de-passe');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));
    expect(await screen.findByText('E-mail ou mot de passe incorrect.')).toBeInTheDocument();
    await user.clear(screen.getByLabelText('Mot de passe'));
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse123');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));
    await waitFor(() => expect(router.state.location.pathname).toBe('/u/kenji'));
  });
});

describe('forgot password', () => {
  async function submit(email: string) {
    const user = userEvent.setup();
    const view = renderRoute(<ForgotPasswordPage />);
    await user.type(screen.getByLabelText('Adresse e-mail'), email);
    await user.click(screen.getByRole('button', { name: 'Envoyer le lien' }));
    const text = (await screen.findByText(RESET_REQUESTED_MESSAGE)).textContent;
    view.unmount();
    return text;
  }

  it('shows the same message whatever the answer (anti-enumeration)', async () => {
    const known = await submit('lea@wave.local');
    const unknown = await submit('personne@wave.local');
    server.use(
      http.post('*/api/v1/auth/password-reset', () =>
        problemJson(
          429,
          { type: 'urn:wave:problem:rate_limited', title: 'x', status: 429, code: 'rate_limited' },
          { 'Retry-After': '60' },
        ),
      ),
    );
    const limited = await submit('lea@wave.local');
    expect(new Set([known, unknown, limited]).size).toBe(1);
    expect(db.state.outbox.filter((m) => m.subject.includes('Réinitialise'))).toHaveLength(1);
  });
});

describe('emailed-token pages', () => {
  it('verify-email strips the token from the URL and posts it once', async () => {
    await loginAs('nour@wave.local');
    const token = Object.keys(db.state.accountTokens)[0] ?? '';
    // Issue a real verification token through the API flow.
    const user = userEvent.setup();
    const banner = renderRoute(<VerifyEmailBanner />);
    await user.click(await screen.findByRole('button', { name: 'Renvoyer l’e-mail' }));
    expect(await screen.findByText(/E-mail envoyé/)).toBeInTheDocument();
    banner.unmount();
    const link = new URL(db.state.outbox[0]!.link);
    expect(token).toBe('');

    let posts = 0;
    server.events.on('request:start', ({ request }) => {
      if (request.url.endsWith('/auth/verify-email')) posts++;
    });
    const { router } = renderRoute(<VerifyEmailPage />, {
      path: '/verify-email',
      url: link.pathname + link.search,
    });
    expect(await screen.findByText(/Adresse e-mail confirmée/)).toBeInTheDocument();
    expect(router.state.location.search).toBe('');
    expect(posts).toBe(1);
    expect(document.querySelector('meta[name="referrer"]')).toHaveAttribute(
      'content',
      'no-referrer',
    );
    expect(db.state.users.find((u) => u.username === 'nour')?.emailVerified).toBe(true);
  });

  it('verify-email explains an invalid or reused token', async () => {
    renderRoute(<VerifyEmailPage />, {
      path: '/verify-email',
      url: '/verify-email?token=deadbeef',
    });
    expect(await screen.findByText('Ce lien est invalide ou a expiré.')).toBeInTheDocument();
  });

  it('reset-password strips the token and sets the new password', async () => {
    const user = userEvent.setup();
    const forgot = renderRoute(<ForgotPasswordPage />);
    await user.type(screen.getByLabelText('Adresse e-mail'), 'kenji@wave.local');
    await user.click(screen.getByRole('button', { name: 'Envoyer le lien' }));
    await screen.findByText(RESET_REQUESTED_MESSAGE);
    forgot.unmount();
    const link = new URL(db.state.outbox[0]!.link);

    const { router } = renderRoute(<ResetPasswordPage />, {
      path: '/reset-password',
      url: link.pathname + link.search,
    });
    await waitFor(() => expect(router.state.location.search).toBe(''));
    await user.type(screen.getByLabelText('Nouveau mot de passe'), 'nouveaumotdepasse');
    await user.type(screen.getByLabelText('Confirmer le mot de passe'), 'nouveaumotdepasse');
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/Ton nouveau mot de passe est enregistré/)).toBeInTheDocument();
    expect(db.state.users.find((u) => u.username === 'kenji')?.password).toBe('nouveaumotdepasse');
  });

  it('banner is hidden for verified accounts', async () => {
    await loginAs('lea@wave.local');
    renderRoute(<VerifyEmailBanner />);
    expect(
      screen.queryByRole('region', { name: 'Adresse e-mail non confirmée' }),
    ).not.toBeInTheDocument();
  });
});

describe('session ended for security (ADR 008)', () => {
  it('login page explains a token_reused logout', () => {
    sessionStore.getState().clearSession('token_reused');
    renderRoute(<LoginPage />, { path: '/login' });
    expect(
      screen.getByText('Session expirée pour des raisons de sécurité, reconnecte-toi.'),
    ).toBeInTheDocument();
  });
});
