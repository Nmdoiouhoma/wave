import { isApiError } from '@/api/errors';
import { ErrorState } from '@/components/ui/States';
import { LockIcon } from '@/components/ui/icons';

/** A 403 from the API (role matrix of ADR 013) shows "Accès réservé"; anything else the usual error. */
export function AdminError({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  if (isApiError(error) && error.status === 403) {
    return (
      <div role="alert" className="flex flex-col items-center gap-3 px-6 py-16 text-center">
        <LockIcon size={28} className="text-muted" />
        <p className="font-semibold">Accès réservé</p>
        <p className="text-muted text-sm">Cette page est réservée à un rôle plus élevé.</p>
      </div>
    );
  }
  return <ErrorState error={error} onRetry={onRetry} />;
}
