import { cx } from './cx';

export type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type Size = 'sm' | 'md' | 'lg';

const VARIANTS: Record<Variant, string> = {
  primary: 'bg-wave text-white shadow-lg shadow-accent/20 hover:brightness-110',
  secondary: 'bg-surface-2 text-fg ring-1 ring-line hover:bg-surface-3',
  ghost: 'text-muted hover:bg-surface-2 hover:text-fg',
  danger: 'bg-danger/15 text-danger ring-1 ring-danger/30 hover:bg-danger/25',
};
const SIZES: Record<Size, string> = {
  sm: 'h-9 px-3 text-sm gap-1.5',
  md: 'h-11 px-4 text-sm gap-2',
  lg: 'h-12 px-6 text-base gap-2',
};

export function buttonClass(variant: Variant = 'primary', size: Size = 'md', extra?: string) {
  return cx(
    'inline-flex shrink-0 items-center justify-center rounded-full font-semibold transition',
    'disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none disabled:hover:brightness-100',
    VARIANTS[variant],
    SIZES[size],
    extra,
  );
}
