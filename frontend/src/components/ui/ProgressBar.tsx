import { cx } from './cx';

export function ProgressBar({
  value,
  label,
  className,
}: {
  value: number | null;
  label: string;
  className?: string;
}) {
  const pct = value === null ? null : Math.round(Math.min(1, Math.max(0, value)) * 100);
  return (
    <div
      role="progressbar"
      aria-label={label}
      aria-valuemin={0}
      aria-valuemax={100}
      aria-valuenow={pct ?? undefined}
      className={cx('bg-surface-3 h-2 w-full overflow-hidden rounded-full', className)}
    >
      <div
        className={cx(
          'bg-wave h-full rounded-full transition-[width] duration-200',
          pct === null && 'w-1/3 animate-pulse',
        )}
        style={pct === null ? undefined : { width: `${pct}%` }}
      />
    </div>
  );
}
