import type { ReactNode } from 'react';
import { cx } from '@/components/ui/cx';

export function Badge({
  tone = 'neutral',
  children,
}: {
  tone?: 'neutral' | 'danger' | 'warning' | 'success';
  children: ReactNode;
}) {
  const tones = {
    neutral: 'bg-surface-2 text-muted',
    danger: 'bg-danger/10 text-danger',
    warning: 'bg-warning/10 text-warning',
    success: 'bg-success/10 text-success',
  };
  return (
    <span className={cx('inline-flex rounded-full px-2 py-0.5 text-xs font-medium', tones[tone])}>
      {children}
    </span>
  );
}

export function ModerationBadge({ moderation }: { moderation: 'visible' | 'hidden' | null }) {
  if (moderation === 'hidden') return <Badge tone="danger">Masqué</Badge>;
  if (moderation === 'visible') return <Badge tone="success">Visible</Badge>;
  return null;
}

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <li className={cx('bg-surface border-line rounded-2xl border p-4', className)}>{children}</li>
  );
}
