import { useTheme, type ThemeChoice } from '@/stores/theme';
import { cx } from './cx';

const OPTIONS: { value: ThemeChoice; label: string }[] = [
  { value: 'light', label: 'Clair' },
  { value: 'dark', label: 'Sombre' },
  { value: 'system', label: 'Système' },
];

/** FB-27: Clair / Sombre / Système, remembered in localStorage. */
export function ThemeToggle({ className }: { className?: string }) {
  const [choice, setChoice] = useTheme();
  return (
    <div
      role="radiogroup"
      aria-label="Thème"
      className={cx('border-line bg-surface-2 inline-flex rounded-full border p-0.5', className)}
    >
      {OPTIONS.map((o) => (
        <button
          key={o.value}
          type="button"
          role="radio"
          aria-checked={choice === o.value}
          onClick={() => setChoice(o.value)}
          className={cx(
            'h-8 flex-1 rounded-full px-3 text-xs font-semibold transition',
            choice === o.value ? 'bg-fg text-bg' : 'text-muted hover:text-fg',
          )}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}
