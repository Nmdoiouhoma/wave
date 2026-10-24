import type { ReactNode } from 'react';
import { cx } from './cx';
import { AlertIcon, CheckIcon } from './icons';

type Tone = 'info' | 'success' | 'error' | 'warning';
const TONES: Record<Tone, string> = {
  info: 'bg-accent/10 text-fg ring-accent/30',
  success: 'bg-success/10 text-success ring-success/30',
  error: 'bg-danger/10 text-danger ring-danger/30',
  warning: 'bg-warning/10 text-warning ring-warning/30',
};

export function Alert({
  tone = 'info',
  children,
  className,
}: {
  tone?: Tone;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cx(
        'flex items-start gap-3 rounded-xl px-4 py-3 text-sm ring-1',
        TONES[tone],
        className,
      )}
    >
      {tone === 'success' ? (
        <CheckIcon className="mt-0.5 shrink-0" size={18} />
      ) : (
        <AlertIcon className="mt-0.5 shrink-0" size={18} />
      )}
      <div className="min-w-0 flex-1">{children}</div>
    </div>
  );
}
