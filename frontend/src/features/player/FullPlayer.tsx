import { useEffect, useRef } from 'react';
import { Link } from 'react-router';
import { IconButton } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { ChevronDownIcon, CloseIcon, NextIcon, PrevIcon } from '@/components/ui/icons';
import { Cover } from '@/components/ui/Media';
import { formatDuration } from '@/lib/format';
import { currentTrack, usePlayer } from '@/stores/player';
import { PlayPauseButton } from './PlayPauseButton';
import { SeekBar } from './SeekBar';
import { VolumeControl } from './VolumeControl';

export function FullPlayer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const track = usePlayer(currentTrack);
  const queue = usePlayer((s) => s.queue);
  const index = usePlayer((s) => s.index);
  const error = usePlayer((s) => s.error);
  const buffering = usePlayer((s) => s.buffering);
  const next = usePlayer((s) => s.next);
  const previous = usePlayer((s) => s.previous);
  const jumpTo = usePlayer((s) => s.jumpTo);
  const removeAt = usePlayer((s) => s.removeAt);
  const closeRef = useRef<HTMLButtonElement>(null);
  const dialogRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;
    const previouslyFocused = document.activeElement as HTMLElement | null;
    closeRef.current?.focus();
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose();
      if (e.key !== 'Tab' || !dialogRef.current) return;
      // Simple focus trap.
      const focusable = dialogRef.current.querySelectorAll<HTMLElement>(
        'button:not(:disabled), a[href], input:not(:disabled), [tabindex]:not([tabindex="-1"])',
      );
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last?.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first?.focus();
      }
    };
    document.addEventListener('keydown', onKey);
    const overflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = overflow;
      previouslyFocused?.focus?.();
    };
  }, [open, onClose]);

  useEffect(() => {
    if (open && !track) onClose();
  }, [open, track, onClose]);

  if (!open || !track) return null;

  return (
    <div
      ref={dialogRef}
      role="dialog"
      aria-modal="true"
      aria-label="Lecteur en plein écran"
      className="bg-bg fixed inset-0 z-50 flex flex-col overflow-y-auto"
    >
      <div
        aria-hidden
        className="pointer-events-none absolute inset-0 opacity-40 blur-3xl"
        style={{
          background: 'radial-gradient(60% 40% at 50% 10%, var(--color-accent), transparent)',
        }}
      />
      <div className="relative mx-auto flex w-full max-w-md flex-1 flex-col px-5 pt-3 pb-8">
        <header className="flex items-center justify-between">
          <IconButton ref={closeRef} label="Réduire le lecteur" onClick={onClose}>
            <ChevronDownIcon size={24} />
          </IconButton>
          <p className="text-muted text-xs font-semibold tracking-widest uppercase">En lecture</p>
          <span className="size-10" />
        </header>

        <div className="mt-6 flex justify-center">
          <Cover
            url={track.coverUrl}
            seed={track.id}
            rounded="rounded-3xl"
            className="aspect-square w-full max-w-[22rem] shadow-2xl shadow-black/60"
          />
        </div>

        <div className="mt-8 min-w-0">
          <Link
            to={`/t/${track.id}`}
            onClick={onClose}
            className="block truncate text-2xl font-bold hover:underline"
          >
            {track.title}
          </Link>
          <Link
            to={`/a/${track.artist.slug}`}
            onClick={onClose}
            className="text-muted hover:text-fg mt-1 block truncate"
          >
            {track.artist.name}
          </Link>
        </div>

        <div className="mt-6">
          <SeekBar />
        </div>
        <p aria-live="polite" className="h-5 text-center text-xs">
          {error ? (
            <span className="text-danger">{error}</span>
          ) : buffering ? (
            <span className="text-faint">Chargement…</span>
          ) : null}
        </p>

        <div className="mt-2 flex items-center justify-center gap-6">
          <IconButton label="Titre précédent" onClick={previous} className="size-12">
            <PrevIcon size={26} />
          </IconButton>
          <PlayPauseButton size="lg" />
          <IconButton
            label="Titre suivant"
            onClick={next}
            disabled={index >= queue.length - 1}
            className="size-12"
          >
            <NextIcon size={26} />
          </IconButton>
        </div>

        <div className="mt-6">
          <VolumeControl />
        </div>

        <section aria-label="File d’attente" className="mt-8">
          <h2 className="text-muted mb-2 text-sm font-semibold">
            File d’attente · {queue.length} titre{queue.length > 1 ? 's' : ''}
          </h2>
          <ol className="flex flex-col gap-1">
            {queue.map((t, i) => (
              <li
                key={`${t.id}-${i}`}
                className={cx(
                  'flex items-center gap-3 rounded-xl px-2 py-1.5',
                  i === index && 'bg-surface-2',
                )}
              >
                <button
                  type="button"
                  onClick={() => jumpTo(i)}
                  className="flex min-w-0 flex-1 items-center gap-3 text-left"
                  aria-current={i === index ? 'true' : undefined}
                  aria-label={`Lire ${t.title}, ${t.artist.name}`}
                >
                  <Cover url={t.coverUrl} seed={t.id} className="size-10" />
                  <span className="min-w-0 flex-1">
                    <span
                      className={cx(
                        'block truncate text-sm',
                        i === index ? 'text-accent-strong font-semibold' : 'font-medium',
                      )}
                    >
                      {t.title}
                    </span>
                    <span className="text-muted block truncate text-xs">{t.artist.name}</span>
                  </span>
                  <span className="text-faint text-xs tabular-nums">
                    {formatDuration(t.durationMs)}
                  </span>
                </button>
                <IconButton
                  label={`Retirer ${t.title} de la file`}
                  onClick={() => removeAt(i)}
                  className="size-8"
                >
                  <CloseIcon size={16} />
                </IconButton>
              </li>
            ))}
          </ol>
        </section>
      </div>
    </div>
  );
}
