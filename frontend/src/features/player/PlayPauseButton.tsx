import { PauseIcon, PlayIcon } from '@/components/ui/icons';
import { cx } from '@/components/ui/cx';
import { usePlayer } from '@/stores/player';

export function PlayPauseButton({
  size = 'md',
  className,
}: {
  size?: 'md' | 'lg';
  className?: string;
}) {
  const status = usePlayer((s) => s.status);
  const toggle = usePlayer((s) => s.togglePlay);
  const playing = status === 'playing' || status === 'loading';
  return (
    <button
      type="button"
      onClick={toggle}
      aria-label={playing ? 'Pause' : 'Lecture'}
      className={cx(
        'bg-fg text-bg inline-flex shrink-0 items-center justify-center rounded-full transition hover:scale-105 active:scale-95',
        size === 'lg' ? 'size-16' : 'size-10',
        className,
      )}
    >
      {status === 'loading' ? (
        <span
          className={cx(
            'border-bg animate-spin rounded-full border-2 border-t-transparent',
            size === 'lg' ? 'size-7' : 'size-4',
          )}
        />
      ) : playing ? (
        <PauseIcon size={size === 'lg' ? 28 : 18} />
      ) : (
        <PlayIcon size={size === 'lg' ? 28 : 18} className={size === 'lg' ? 'ml-1' : 'ml-0.5'} />
      )}
    </button>
  );
}
