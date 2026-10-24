import { useEffect, useRef } from 'react';
import { useVerifyEmail } from '@/api/queries/auth';
import { Alert } from '@/components/ui/Alert';
import { ButtonLink } from '@/components/ui/Button';
import { Spinner } from '@/components/ui/Spinner';
import { errorMessage } from '@/lib/errorMessages';
import { useSession } from '@/stores/session';
import { AuthLayout } from './AuthLayout';
import { useTokenFromUrl } from './useTokenFromUrl';

export function VerifyEmailPage() {
  const token = useTokenFromUrl();
  const verify = useVerifyEmail();
  const authenticated = useSession((s) => s.status === 'authenticated');
  const sent = useRef<string | null>(null);
  const { mutate } = verify;

  useEffect(() => {
    // Exactly one POST per token (StrictMode runs effects twice in dev).
    if (!token || sent.current === token) return;
    sent.current = token;
    mutate(token);
  }, [token, mutate]);

  const cta = authenticated ? (
    <ButtonLink to="/" size="lg">
      Continuer
    </ButtonLink>
  ) : (
    <ButtonLink to="/login" size="lg">
      Se connecter
    </ButtonLink>
  );

  if (!token) {
    return (
      <AuthLayout title="Lien incomplet">
        <div className="flex flex-col gap-4">
          <Alert tone="error">Ce lien de confirmation ne contient pas de jeton.</Alert>
          {cta}
        </div>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title="Confirmation de l’adresse e-mail">
      <div className="flex flex-col gap-4" aria-live="polite">
        {(verify.isPending || verify.isIdle) && (
          <div className="text-muted flex items-center gap-3">
            <Spinner /> Vérification en cours…
          </div>
        )}
        {verify.isSuccess && (
          <Alert tone="success">
            Adresse e-mail confirmée. Vous pouvez maintenant importer des titres et gérer vos
            artistes.
          </Alert>
        )}
        {verify.isError && (
          <Alert tone="error">
            {errorMessage(verify.error)}
            {authenticated &&
              ' Vous pouvez demander un nouveau lien depuis le bandeau en haut de l’application.'}
          </Alert>
        )}
        {!verify.isPending && cta}
      </div>
    </AuthLayout>
  );
}
