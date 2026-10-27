import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useConfirmPasswordReset } from '@/api/queries/auth';
import { isApiError } from '@/api/errors';
import { Alert } from '@/components/ui/Alert';
import { Button, ButtonLink } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from './AuthLayout';
import { formErrors } from './formErrors';
import { useTokenFromUrl } from './useTokenFromUrl';

export function ResetPasswordPage() {
  const token = useTokenFromUrl();
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [local, setLocal] = useState<{ password?: string; confirm?: string }>({});
  const reset = useConfirmPasswordReset();
  const server = formErrors(reset.error, ['newPassword']);
  const invalidToken =
    !token || (isApiError(reset.error) && reset.error.code === 'account_token_invalid');

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    const errs: typeof local = {};
    if (password.length < 10) errs.password = 'Au moins 10 caractères.';
    else if (password.length > 128) errs.password = '128 caractères maximum.';
    if (confirm !== password) errs.confirm = 'Les deux mots de passe ne correspondent pas.';
    setLocal(errs);
    if (Object.keys(errs).length || !token) return;
    reset.mutate({ token, newPassword: password });
  };

  if (reset.isSuccess) {
    return (
      <AuthLayout title="Mot de passe modifié">
        <div className="flex flex-col gap-4">
          <Alert tone="success">
            Ton nouveau mot de passe est enregistré. Par sécurité, toutes tes sessions ont été
            fermées.
          </Alert>
          <ButtonLink to="/login" size="lg">
            Se connecter
          </ButtonLink>
        </div>
      </AuthLayout>
    );
  }

  if (invalidToken) {
    return (
      <AuthLayout title="Lien invalide">
        <div className="flex flex-col gap-4">
          <Alert tone="error">
            Ce lien de réinitialisation est invalide, déjà utilisé ou expiré (validité : 1 heure).
          </Alert>
          <ButtonLink to="/forgot-password" size="lg">
            Demander un nouveau lien
          </ButtonLink>
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Nouveau mot de passe"
      subtitle="Choisis un mot de passe d’au moins 10 caractères."
      footer={
        <Link to="/login" className="text-fg font-semibold hover:underline">
          Retour à la connexion
        </Link>
      }
    >
      <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
        {server.message && <Alert tone="error">{server.message}</Alert>}
        <TextField
          label="Nouveau mot de passe"
          type="password"
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          error={local.password ?? server.fields.newPassword}
        />
        <TextField
          label="Confirmer le mot de passe"
          type="password"
          autoComplete="new-password"
          value={confirm}
          onChange={(e) => setConfirm(e.target.value)}
          error={local.confirm}
        />
        <Button type="submit" size="lg" loading={reset.isPending}>
          Enregistrer
        </Button>
      </form>
    </AuthLayout>
  );
}
