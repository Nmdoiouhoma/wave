import type { Track } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { LockIcon } from '@/components/ui/icons';

export function TrackStatusBadge({ track }: { track: Track }) {
  const badges: { label: string; className: string; icon?: boolean }[] = [];
  if (track.status === 'processing')
    badges.push({ label: 'Traitement…', className: 'bg-accent/15 text-accent-strong' });
  if (track.status === 'failed')
    badges.push({ label: 'Échec', className: 'bg-danger/15 text-danger' });
  if (track.visibility === 'private')
    badges.push({ label: 'Privé', className: 'bg-surface-3 text-muted', icon: true });
  if (track.moderation === 'hidden')
    badges.push({ label: 'Masqué', className: 'bg-warning/15 text-warning' });
  if (!badges.length) return null;
  return (
    <span className="flex shrink-0 gap-1">
      {badges.map((b) => (
        <span
          key={b.label}
          className={cx(
            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
            b.className,
          )}
        >
          {b.icon && <LockIcon size={11} />}
          {b.label}
        </span>
      ))}
    </span>
  );
}
