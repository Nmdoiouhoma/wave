import { useMe, useResendVerification } from '@/api/queries/me';
import { isApiError } from '@/api/errors';
import { useQueryClient } from '@tanstack/react-query';
import { keys } from '@/api/queries/keys';
import { MailIcon } from '@/components/ui/icons';
import { errorMessage } from '@/lib/errorMessages';

export function VerifyEmailBanner() {
  const { data: me } = useMe();
  const resend = useResendVerification();
  const qc = useQueryClient();
  if (!me || me.emailVerified) return null;

  const onResend = () =>
    resend.mutate(undefined, {
      onError: (e) => {
        if (isApiError(e) && e.code === 'email_already_verified')
          void qc.invalidateQueries({ queryKey: keys.me() });
      },
    });

  return (
    <div
      role="region"
      aria-label="Adresse e-mail non confirmée"
      className="border-warning/20 bg-warning/10 border-b px-4 py-2.5 text-sm"
    >
      <div className="mx-auto flex max-w-5xl flex-wrap items-center gap-x-3 gap-y-1">
        <MailIcon size={18} className="text-warning shrink-0" />
        <p className="text-fg/90 min-w-0 flex-1">
          Confirmez votre adresse <strong className="font-semibold">{me.email}</strong> pour
          importer des titres et gérer vos artistes.
        </p>
        <div aria-live="polite" className="flex items-center gap-2">
          {resend.isSuccess ? (
            <span className="text-success">E-mail envoyé, vérifiez votre boîte de réception.</span>
          ) : resend.isError ? (
            <span className="text-danger">{errorMessage(resend.error)}</span>
          ) : null}
          {!resend.isSuccess && (
            <button
              type="button"
              onClick={onResend}
              disabled={resend.isPending}
              className="bg-warning/20 text-warning hover:bg-warning/30 rounded-full px-3 py-1 font-semibold disabled:opacity-50"
            >
              {resend.isPending ? 'Envoi…' : 'Renvoyer l’e-mail'}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
