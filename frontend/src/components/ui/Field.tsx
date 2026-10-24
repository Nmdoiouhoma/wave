import {
  useId,
  type InputHTMLAttributes,
  type ReactNode,
  type SelectHTMLAttributes,
  type TextareaHTMLAttributes,
} from 'react';
import { cx } from './cx';
import { ChevronDownIcon } from './icons';

const control =
  'w-full rounded-xl bg-surface-2 px-4 text-fg ring-1 ring-line placeholder:text-faint transition focus:outline-none focus:ring-2 focus:ring-accent aria-[invalid=true]:ring-danger disabled:opacity-60';

interface FieldShellProps {
  label: string;
  error?: string | null;
  hint?: ReactNode;
  children: (ids: { id: string; describedBy: string | undefined; invalid: boolean }) => ReactNode;
}

function FieldShell({ label, error, hint, children }: FieldShellProps) {
  const id = useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;
  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-fg/90 text-sm font-medium">
        {label}
      </label>
      {children({ id, describedBy, invalid: !!error })}
      {hint && !error && (
        <p id={hintId} className="text-faint text-xs">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-danger text-xs font-medium">
          {error}
        </p>
      )}
    </div>
  );
}

type Common = { label: string; error?: string | null; hint?: ReactNode };

export function TextField({
  label,
  error,
  hint,
  className,
  ...rest
}: Common & InputHTMLAttributes<HTMLInputElement>) {
  return (
    <FieldShell label={label} error={error} hint={hint}>
      {({ id, describedBy, invalid }) => (
        <input
          id={id}
          aria-describedby={describedBy}
          aria-invalid={invalid}
          className={cx(control, 'h-12', className)}
          {...rest}
        />
      )}
    </FieldShell>
  );
}

export function TextArea({
  label,
  error,
  hint,
  className,
  ...rest
}: Common & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  return (
    <FieldShell label={label} error={error} hint={hint}>
      {({ id, describedBy, invalid }) => (
        <textarea
          id={id}
          aria-describedby={describedBy}
          aria-invalid={invalid}
          rows={3}
          className={cx(control, 'py-3', className)}
          {...rest}
        />
      )}
    </FieldShell>
  );
}

export function SelectField({
  label,
  error,
  hint,
  className,
  children,
  ...rest
}: Common & SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <FieldShell label={label} error={error} hint={hint}>
      {({ id, describedBy, invalid }) => (
        <div className="relative">
          <select
            id={id}
            aria-describedby={describedBy}
            aria-invalid={invalid}
            className={cx(control, 'h-12 appearance-none pr-10', className)}
            {...rest}
          >
            {children}
          </select>
          <ChevronDownIcon
            size={18}
            className="text-muted pointer-events-none absolute top-1/2 right-4 -translate-y-1/2"
          />
        </div>
      )}
    </FieldShell>
  );
}

export function Toggle({
  label,
  description,
  checked,
  onChange,
  disabled,
}: {
  label: string;
  description?: string;
  checked: boolean;
  onChange: (v: boolean) => void;
  disabled?: boolean;
}) {
  const id = useId();
  return (
    <div className="bg-surface-2 ring-line flex items-center justify-between gap-4 rounded-xl px-4 py-3 ring-1">
      <div>
        <label htmlFor={id} className="text-sm font-medium">
          {label}
        </label>
        {description && <p className="text-faint text-xs">{description}</p>}
      </div>
      <button
        id={id}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onChange(!checked)}
        className={cx(
          'relative h-7 w-12 shrink-0 rounded-full transition disabled:opacity-50',
          checked ? 'bg-accent' : 'bg-surface-3',
        )}
      >
        <span
          className={cx(
            'absolute top-1 left-1 size-5 rounded-full bg-white shadow transition',
            checked && 'translate-x-5',
          )}
        />
      </button>
    </div>
  );
}
