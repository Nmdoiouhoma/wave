import type { ReactNode } from 'react';
import { errorMessage } from '@/lib/errorMessages';
import { Button } from './Button';
import { AlertIcon } from './icons';

export function EmptyState({
  icon,
  title,
  children,
}: {
  icon?: ReactNode;
  title: string;
  children?: ReactNode;
}) {
  return (
    <div className="border-line flex flex-col items-center gap-3 rounded-2xl border border-dashed px-6 py-10 text-center">
      {icon && <div className="text-faint">{icon}</div>}
      <p className="font-semibold">{title}</p>
      {children && <div className="text-muted text-sm">{children}</div>}
    </div>
  );
}

export function ErrorState({ error, onRetry }: { error: unknown; onRetry?: () => void }) {
  return (
    <div role="alert" className="flex flex-col items-center gap-3 px-6 py-10 text-center">
      <AlertIcon size={28} className="text-danger" />
      <p className="text-muted text-sm">{errorMessage(error)}</p>
      {onRetry && (
        <Button variant="secondary" size="sm" onClick={onRetry}>
          Réessayer
        </Button>
      )}
    </div>
  );
}
