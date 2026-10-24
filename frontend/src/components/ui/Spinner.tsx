import { cx } from './cx';

export function Spinner({
  className,
  label = 'Chargement…',
}: {
  className?: string;
  label?: string;
}) {
  return (
    <span role="status" className={cx('inline-flex items-center', className)}>
      <span
        aria-hidden
        className="size-5 animate-spin rounded-full border-2 border-current border-t-transparent opacity-80"
      />
      <span className="sr-only">{label}</span>
    </span>
  );
}

export function PageSpinner() {
  return (
    <div className="text-muted flex min-h-[40vh] items-center justify-center">
      <Spinner />
    </div>
  );
}
