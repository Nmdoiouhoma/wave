// W-75: app-wide banner. Text only (React escapes it, no HTML path). A dismissal is stored per
// `id` + `updatedAt` in local storage, so an edited announcement shows again. Storage may throw.
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { cx } from '@/components/ui/cx';
import { CloseIcon } from '@/components/ui/icons';
import { announcementsApi, type Announcement } from './announcementsApi';
import { dismissKey, readDismissed, writeDismissed } from './dismissed';

export function AnnouncementBanner() {
  const q = useQuery({
    queryKey: ['announcements', 'active'],
    queryFn: announcementsApi.active,
    staleTime: 5 * 60_000,
    retry: false,
  });
  const [dismissed, setDismissed] = useState<string[]>(readDismissed);
  const shown = (q.data?.items ?? []).filter((a) => !dismissed.includes(dismissKey(a)));
  if (shown.length === 0) return null;

  const dismiss = (a: Announcement) => {
    const next = [...dismissed, dismissKey(a)];
    setDismissed(next);
    writeDismissed(next);
  };

  return (
    <section aria-label="Annonces">
      {shown.map((a) => (
        <div
          key={dismissKey(a)}
          role={a.level === 'warning' ? 'alert' : 'status'}
          className={cx(
            'border-b px-4 py-2.5 text-sm',
            a.level === 'warning'
              ? 'border-warning/20 bg-warning/10'
              : 'border-line bg-surface-2',
          )}
        >
          <div className="mx-auto flex max-w-5xl items-start gap-3">
            <p className="min-w-0 flex-1 break-words whitespace-pre-line">
              <strong className="font-semibold">{a.title}</strong>
              <span className="text-fg/90"> {a.body}</span>
            </p>
            <button
              type="button"
              onClick={() => dismiss(a)}
              aria-label={`Fermer l’annonce ${a.title}`}
              className="text-muted hover:text-fg -m-2 flex size-11 shrink-0 items-center justify-center"
            >
              <CloseIcon size={18} />
            </button>
          </div>
        </div>
      ))}
    </section>
  );
}
