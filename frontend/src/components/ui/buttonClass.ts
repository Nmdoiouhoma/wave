import { cx } from './cx';

export type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';
export type Size = 'sm' | 'md' | 'lg';

const VARIANTS: Record<Variant, string> = {
  primary: 'bg-accent text-accent-contrast hover:brightness-110',
  secondary: 'border-fg text-fg hover:bg-surface-2 border-[1.5px] bg-transparent',
  ghost: 'text-muted hover:bg-surface-2 hover:text-fg',
  danger: 'border-danger/50 text-danger hover:bg-danger/10 border bg-transparent',
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
