// W-38: "+" menu. Bottom sheet on mobile, popover next to the rail button on desktop.
import { useEffect, useId, useLayoutEffect, useRef, type RefObject } from 'react';
import { Link } from 'react-router';
import { PlusIcon, UploadIcon } from '@/components/ui/icons';
import { Sheet } from '@/components/ui/Sheet';

const ENTRIES = [
  {
    to: '/posts/new',
    label: 'Publier un extrait',
    hint: 'Un court passage d’un morceau, dans le fil',
    icon: PlusIcon,
  },
  {
    to: '/upload',
    label: 'Importer des morceaux',
    hint: 'MP3, AAC ou vidéo MP4',
    icon: UploadIcon,
  },
] as const;

function Entries({ onPick }: { onPick: () => void }) {
  return (
    <ul className="flex flex-col gap-1">
      {ENTRIES.map(({ to, label, hint, icon: Icon }) => (
        <li key={to}>
          <Link
            to={to}
            onClick={onPick}
            className="hover:bg-surface-2 flex min-h-11 items-center gap-3 rounded-xl px-3 py-2"
          >
            <span className="bg-wave flex size-9 shrink-0 items-center justify-center rounded-xl text-white">
              <Icon size={18} />
            </span>
            <span className="min-w-0">
              <span className="block text-sm font-semibold">{label}</span>
              <span className="text-muted block text-xs">{hint}</span>
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}

export function CreateMenu({
  open,
  variant,
  onClose,
  trigger,
}: {
  open: boolean;
  variant: 'sheet' | 'popover';
  onClose: () => void;
  /** The "+" button: focus goes back to it when the menu closes. */
  trigger: RefObject<HTMLButtonElement | null>;
}) {
  if (variant === 'sheet') {
    return (
      <Sheet open={open} title="Créer" onClose={onClose}>
        <Entries onPick={onClose} />
      </Sheet>
    );
  }
  return open ? <Popover onClose={onClose} trigger={trigger} /> : null;
}

function Popover({
  onClose,
  trigger,
}: {
  onClose: () => void;
  trigger: RefObject<HTMLButtonElement | null>;
}) {
  const ref = useRef<HTMLDivElement>(null);
  const titleId = useId();

  // Anchor next to the "+" button of the rail.
  useLayoutEffect(() => {
    const rect = trigger.current?.getBoundingClientRect();
    if (rect && ref.current) ref.current.style.top = `${rect.top}px`;
  }, [trigger]);

  useEffect(() => {
    ref.current?.querySelector<HTMLElement>('a[href]')?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
    };
    document.addEventListener('keydown', onKey);
    const button = trigger.current;
    return () => {
      document.removeEventListener('keydown', onKey);
      button?.focus();
    };
  }, [onClose, trigger]);

  return (
    <>
      <div aria-hidden className="fixed inset-0 z-[60]" onClick={onClose} />
      <div
        ref={ref}
        role="dialog"
        aria-labelledby={titleId}
        className="bg-surface ring-line fixed top-40 left-60 z-[61] ml-2 w-72 rounded-2xl p-2 shadow-2xl ring-1 shadow-black/50"
      >
        <h2 id={titleId} className="text-muted px-3 pt-1 pb-2 text-xs font-semibold uppercase">
          Créer
        </h2>
        <Entries onPick={onClose} />
      </div>
    </>
  );
}
