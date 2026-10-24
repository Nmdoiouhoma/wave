import { useEffect, useRef, useState, type ReactNode } from 'react';
import { checkImageFile, IMAGE_ACCEPT } from '@/lib/files';
import { errorMessage } from '@/lib/errorMessages';
import { Alert } from './Alert';
import { Button } from './Button';
import { TrashIcon, UploadIcon } from './icons';

/** Image processing is async (202): poll the owner entity until the URL changes, max 2 min. */
const POLL_MS = 3_000;
const TIMEOUT_MS = 120_000;

interface Pending {
  previousUrl: string | null;
  startedAt: number;
}

export interface ImageUploaderProps {
  currentUrl: string | null;
  preview: ReactNode;
  upload: (file: File) => Promise<unknown>;
  remove?: () => Promise<unknown>;
  refetch: () => unknown;
  /** Non-null = uploads disabled, with this explanation (e.g. unverified email). */
  disabledReason?: string | null;
  label?: string;
}

export function ImageUploader({
  currentUrl,
  preview,
  upload,
  remove,
  refetch,
  disabledReason,
  label = 'Changer l’image',
}: ImageUploaderProps) {
  const input = useRef<HTMLInputElement>(null);
  const [pending, setPending] = useState<Pending | null>(null);
  const [timedOut, setTimedOut] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const done = pending !== null && currentUrl !== pending.previousUrl;
  const processing = pending !== null && !done && !timedOut;

  useEffect(() => {
    if (!processing || !pending) return;
    const id = setInterval(() => {
      if (Date.now() - pending.startedAt > TIMEOUT_MS) setTimedOut(true);
      else void refetch();
    }, POLL_MS);
    return () => clearInterval(id);
  }, [processing, pending, refetch]);

  const onPick = async (file: File | undefined) => {
    if (!file) return;
    const problem = checkImageFile(file);
    setError(problem);
    if (problem) return;
    setBusy(true);
    try {
      await upload(file);
      setTimedOut(false);
      setPending({ previousUrl: currentUrl, startedAt: Date.now() });
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
      if (input.current) input.current.value = '';
    }
  };

  const onRemove = async () => {
    if (!remove) return;
    setBusy(true);
    setError(null);
    try {
      await remove();
      setPending(null);
      await refetch();
    } catch (e) {
      setError(errorMessage(e));
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center gap-4">
        <div className="relative">
          {preview}
          {processing && (
            <span className="absolute inset-0 flex items-center justify-center rounded-[inherit] bg-black/50">
              <span className="size-6 animate-spin rounded-full border-2 border-white border-t-transparent" />
            </span>
          )}
        </div>
        <div className="flex flex-wrap gap-2">
          <input
            ref={input}
            type="file"
            accept={IMAGE_ACCEPT}
            className="sr-only"
            tabIndex={-1}
            aria-hidden
            onChange={(e) => void onPick(e.target.files?.[0])}
          />
          <Button
            variant="secondary"
            size="sm"
            icon={<UploadIcon size={16} />}
            loading={busy}
            disabled={!!disabledReason || processing}
            onClick={() => input.current?.click()}
          >
            {label}
          </Button>
          {remove && currentUrl && (
            <Button
              variant="ghost"
              size="sm"
              icon={<TrashIcon size={16} />}
              disabled={busy || processing}
              onClick={() => void onRemove()}
            >
              Retirer
            </Button>
          )}
        </div>
      </div>
      <p className="text-faint text-xs">JPEG, PNG ou WebP, 5 Mo maximum.</p>
      {disabledReason && <p className="text-warning text-xs">{disabledReason}</p>}
      <div aria-live="polite">
        {processing && <Alert tone="info">Image reçue, traitement en cours…</Alert>}
        {done && <Alert tone="success">Image mise à jour.</Alert>}
        {timedOut && !done && (
          <Alert tone="warning">
            L’image n’a pas pu être traitée. Essayez avec un autre fichier.
          </Alert>
        )}
        {error && <Alert tone="error">{error}</Alert>}
      </div>
    </div>
  );
}
