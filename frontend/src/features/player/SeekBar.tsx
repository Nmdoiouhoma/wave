import { formatDuration } from '@/lib/format';
import { usePlayer } from '@/stores/player';
import { cx } from '@/components/ui/cx';

export function SeekBar({ compact = false }: { compact?: boolean }) {
  const position = usePlayer((s) => s.positionMs);
  const duration = usePlayer((s) => s.durationMs);
  const seek = usePlayer((s) => s.seek);
  const max = Math.max(duration, 1);
  const pct = `${Math.min(100, (position / max) * 100)}%`;
  return (
    <div className={cx('w-full', compact ? '' : 'flex flex-col gap-1')}>
      <input
        type="range"
        className="range"
        min={0}
        max={max}
        step={1000}
        value={Math.min(position, max)}
        disabled={!duration}
        onChange={(e) => seek(Number(e.target.value))}
        aria-label="Position de lecture"
        aria-valuetext={`${formatDuration(position)} sur ${formatDuration(duration)}`}
        style={{ ['--pct' as string]: pct }}
      />
      {!compact && (
        <div className="text-faint flex justify-between text-xs tabular-nums">
          <span>{formatDuration(position)}</span>
          <span>{formatDuration(duration)}</span>
        </div>
      )}
    </div>
  );
}
