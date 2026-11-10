import type { ReactNode } from 'react';
import type { Track } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { PeopleIcon } from '@/components/ui/icons';
import { audienceInfo } from './audience';

export function TrackStatusBadge({ track }: { track: Track }) {
  const badges: { label: string; className: string; icon?: ReactNode; testId?: string }[] = [];
  if (track.status === 'processing')
    badges.push({ label: 'Traitement…', className: 'bg-accent/15 text-accent-strong' });
  if (track.status === 'failed')
    badges.push({ label: 'Échec', className: 'bg-danger/15 text-danger' });
  // FB-18: the audience is shown to its owner only. Others get `public` (no badge) or null:
  // restricted audience that includes them, not disclosed (ADR 012) → "Partagé avec toi".
  if (!track.isOwner && track.visibility === null)
    badges.push({
      label: 'Partagé avec toi',
      className: 'bg-surface-3 text-muted',
      icon: <PeopleIcon size={11} />,
      testId: 'audience-badge',
    });
  if (track.isOwner && track.visibility && track.visibility !== 'public') {
    const a = audienceInfo(track.visibility);
    badges.push({
      label: a.label,
      className: 'bg-surface-3 text-muted',
      icon: a.icon(11),
      testId: 'audience-badge',
    });
  }
  if (track.moderation === 'hidden')
    badges.push({ label: 'Masqué', className: 'bg-warning/15 text-warning' });
  if (!badges.length) return null;
  return (
    <span className="flex shrink-0 gap-1">
      {badges.map((b) => (
        <span
          key={b.label}
          data-testid={b.testId}
          className={cx(
            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
            b.className,
          )}
        >
          {b.icon}
          {b.label}
        </span>
      ))}
    </span>
  );
}
