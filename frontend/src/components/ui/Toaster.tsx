import { useToasts } from '@/stores/toast';
import { cx } from './cx';

/** Polite live region: confirmations ("Ajouté à « X »") and errors of background actions. */
export function Toaster() {
  const toasts = useToasts();
  return (
    <div
      aria-live="polite"
      className="pointer-events-none fixed inset-x-0 top-16 z-[70] flex flex-col items-center gap-2 px-4"
    >
      {toasts.map((t) => (
        <p
          key={t.id}
          role={t.tone === 'error' ? 'alert' : 'status'}
          className={cx(
            'pointer-events-auto max-w-sm rounded-full px-4 py-2 text-sm font-medium shadow-xl ring-1',
            t.tone === 'error'
              ? 'bg-danger/20 text-danger ring-danger/40'
              : t.tone === 'success'
                ? 'bg-surface-2 text-fg ring-success/40'
                : 'bg-surface-2 text-fg ring-line',
          )}
        >
          {t.message}
        </p>
      ))}
    </div>
  );
}
