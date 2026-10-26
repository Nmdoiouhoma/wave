import { MAILPIT_URL } from '@/lib/config';

/** Dev only (FB-02): where the emails land locally. Renders nothing in production builds. */
export function MailpitHint({ className }: { className?: string }) {
  if (!MAILPIT_URL) return null;
  return (
    <a
      href={MAILPIT_URL}
      target="_blank"
      rel="noreferrer"
      className={className ?? 'text-accent-strong text-xs font-semibold underline'}
    >
      Ouvrir Mailpit (dev)
    </a>
  );
}
