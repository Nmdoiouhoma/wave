// Modal sheet: bottom sheet on mobile, centered dialog on larger screens. Focus trap + Escape.
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { IconButton } from './Button';
import { CloseIcon } from './icons';

export function Sheet({
  open,
  title,
  onClose,
  children,
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const titleId = useId();

  useEffect(() => {
    if (!open) return;
    const previouslyFocused = document.activeElement as HTMLElement | null;
    const panel = ref.current;
    const focusables = () =>
      panel?.querySelectorAll<HTMLElement>(
        'button:not(:disabled), a[href], input:not(:disabled), textarea, select, [tabindex]:not([tabindex="-1"])',
      ) ?? [];
    (panel?.querySelector<HTMLElement>('[data-autofocus]') ?? focusables()[0])?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key !== 'Tab') return;
      const list = focusables();
      const first = list[0];
      const last = list[list.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last?.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first?.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('keydown', onKey);
      previouslyFocused?.focus?.();
    };
  }, [open, onClose]);

  if (!open) return null;
  return (
    <div className="fixed inset-0 z-[60] flex items-end justify-center sm:items-center">
      <div
        aria-hidden
        className="absolute inset-0 bg-black/60 backdrop-blur-sm"
        onClick={onClose}
      />
      <div
        ref={ref}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className="bg-surface ring-line pb-safe relative max-h-[85dvh] w-full overflow-y-auto rounded-t-3xl p-5 ring-1 sm:max-w-md sm:rounded-3xl"
      >
        <div aria-hidden className="bg-surface-3 mx-auto mb-3 h-1 w-10 rounded-full sm:hidden" />
        <div className="mb-4 flex items-center justify-between gap-3">
          <h2 id={titleId} className="truncate text-lg font-semibold">
            {title}
          </h2>
          <IconButton label="Fermer" onClick={onClose}>
            <CloseIcon size={18} />
          </IconButton>
        </div>
        {children}
      </div>
    </div>
  );
}
