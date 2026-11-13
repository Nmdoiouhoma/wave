import type { ReactNode } from 'react';
import { cx } from '@/components/ui/cx';
import type { AdminUser } from './adminApi';
import { ROLE_LABELS } from './permissions';

function Pill({ tone, children }: { tone: 'neutral' | 'accent' | 'danger' | 'warning'; children: ReactNode }) {
  const tones = {
    neutral: 'bg-surface-2 text-muted',
    accent: 'bg-accent/15 text-fg',
    danger: 'bg-danger/10 text-danger',
    warning: 'bg-warning/10 text-warning',
  };
  return (
    <span className={cx('rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap', tones[tone])}>
      {children}
    </span>
  );
}

/** Role (when above user) and state of an account. */
export function UserBadges({ user }: { user: Pick<AdminUser, 'role' | 'suspendedAt' | 'emailVerified'> }) {
  return (
    <>
      {user.role !== 'ROLE_USER' && <Pill tone="accent">{ROLE_LABELS[user.role]}</Pill>}
      {user.suspendedAt && <Pill tone="danger">Suspendu</Pill>}
      {!user.suspendedAt && !user.emailVerified && <Pill tone="warning">Non vérifié</Pill>}
    </>
  );
}
