import { useState, type FormEvent } from 'react';
import { Link } from 'react-router';
import { useRequestPasswordReset } from '@/api/queries/auth';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { TextField } from '@/components/ui/Field';
import { AuthLayout } from './AuthLayout';

/** Anti-enumeration: whatever the server answers, the user sees the same message. */
export const RESET_REQUESTED_MESSAGE =
  'Si un compte existe pour cette adresse, un e-mail contenant un lien de réinitialisation vient d’être envoyé. Le lien est valable 1 heure.';

export function ForgotPasswordPage() {
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [sent, setSent] = useState(false);
  const request = useRequestPasswordReset();

  const onSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim())) {
      setError('Adresse e-mail invalide.');
      return;
    }
    setError(null);
    request.mutate(email.trim().toLowerCase(), { onSettled: () => setSent(true) });
  };

  return (
    <AuthLayout
      title="Mot de passe oublié"
      subtitle="Indiquez votre adresse e-mail, nous vous enverrons un lien."
      footer={
        <Link to="/login" className="text-fg font-semibold hover:underline">
          Retour à la connexion
        </Link>
      }
    >
      {sent ? (
        <Alert tone="success">{RESET_REQUESTED_MESSAGE}</Alert>
      ) : (
        <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
          <TextField
            label="Adresse e-mail"
            type="email"
            autoComplete="email"
            inputMode="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            error={error}
          />
          <Button type="submit" size="lg" loading={request.isPending}>
            Envoyer le lien
          </Button>
        </form>
      )}
    </AuthLayout>
  );
}
