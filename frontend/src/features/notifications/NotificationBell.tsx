// W-46: bell with unread badge. Reads the shared count; the shell owns the polling.
import { Link } from 'react-router';
import { cx } from '@/components/ui/cx';
import { badgeText } from './notificationText';
import { useBellLabel, useUnreadCount } from './queries';

export function BellIcon({ size = 24, className }: { size?: number; className?: string }) {
  return (
    <svg
      width={size}
      height={size}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
      className={className}
    >
      <path d="M6 9a6 6 0 0 1 12 0c0 6 2.5 7.5 2.5 7.5h-17S6 15 6 9" />
      <path d="M10.3 20a1.9 1.9 0 0 0 3.4 0" />
    </svg>
  );
}

export function UnreadBadge({ className }: { className?: string }) {
  const count = useUnreadCount().data?.unreadCount ?? 0;
  const text = badgeText(count);
  if (!text) return null;
  return (
    <span
      aria-hidden
      data-testid="unread-badge"
      className={cx(
        'bg-wave flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] leading-none font-bold text-white',
        className,
      )}
    >
      {text}
    </span>
  );
}

/** Icon-only bell for the mobile headers (Pour toi, Profil). */
export function NotificationBell({ className }: { className?: string }) {
  const label = useBellLabel();
  return (
    <Link
      to="/notifications"
      aria-label={label}
      className={cx('relative flex size-11 items-center justify-center rounded-full', className)}
    >
      <BellIcon size={22} />
      <UnreadBadge className="absolute top-1 right-0.5" />
    </Link>
  );
}
