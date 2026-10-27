import { useState, type FormEvent } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { useLogin } from '@/api/queries/auth';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { MOCKS_ENABLED } from '@/lib/config';
import { TOKEN_REUSED_MESSAGE } from '@/lib/errorMessages';
import { useSession } from '@/stores/session';
import { AuthLayout } from './AuthLayout';
import { formErrors } from './formErrors';
import { safeNext } from './safeNext';

export function LoginPage() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const login = useLogin();
  const navigate = useNavigate();
  const [params] = useSearchParams();
  const { fields, message } = formErrors(login.error, ['email', 'password']);
  const endedForSecurity = useSession((s) => s.endReason === 'token_reused');

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    login.mutate(
      { email: email.trim(), password },
      { onSuccess: () => void navigate(safeNext(params.get('next')), { replace: true }) },
    );
  };

  return (
    <AuthLayout
      title="Bon retour"
      subtitle="Connecte-toi pour retrouver ta musique."
      footer={
        <>
          Pas encore de compte ?{' '}
          <Link to="/register" className="text-fg font-semibold hover:underline">
            Créer un compte
          </Link>
        </>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        {endedForSecurity && !login.isPending && (
          <Alert tone="warning">{TOKEN_REUSED_MESSAGE}</Alert>
        )}
        {message && <Alert tone="error">{message}</Alert>}
        <TextField
          label="Adresse e-mail"
          type="email"
          autoComplete="email"
          inputMode="email"
          required
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          error={fields.email}
        />
        <TextField
          label="Mot de passe"
          type="password"
          autoComplete="current-password"
          required
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={fields.password}
        />
        <div className="-mt-1 text-right">
          <Link to="/forgot-password" className="text-muted hover:text-fg text-sm">
            Mot de passe oublié ?
          </Link>
        </div>
        <Button type="submit" size="lg" loading={login.isPending} disabled={!email || !password}>
          Se connecter
        </Button>
        {MOCKS_ENABLED && (
          <p className="text-faint text-center text-xs">
            Mode démo : lea@wave.local (vérifiée) ou nour@wave.local (non vérifiée), mot de passe
            motdepasse123 ·{' '}
            <Link to="/dev/mailbox" className="underline">
              boîte mail de démo
            </Link>
          </p>
        )}
      </form>
    </AuthLayout>
  );
}
