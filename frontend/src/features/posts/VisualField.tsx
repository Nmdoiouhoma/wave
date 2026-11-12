// W-35: optional visual (image or short MP4). Checks type, size and video length before upload.
import { useEffect, useId, useRef, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { CloseIcon } from '@/components/ui/icons';
import { formatBytes } from '@/lib/format';
import {
  VISUAL_ACCEPT,
  checkVideoLength,
  checkVisualFile,
  isVideo,
  readVideoDuration,
} from './postRules';

export function VisualField({
  file,
  onChange,
  disabled,
}: {
  file: File | null;
  onChange: (f: File | null) => void;
  disabled?: boolean;
}) {
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<string | null>(null);

  const previewRef = useRef<string | null>(null);
  useEffect(
    () => () => {
      if (previewRef.current) URL.revokeObjectURL(previewRef.current);
    },
    [],
  );
  const setFile = (next: File | null) => {
    if (previewRef.current) URL.revokeObjectURL(previewRef.current);
    previewRef.current = next ? URL.createObjectURL(next) : null;
    setPreview(previewRef.current);
    onChange(next);
  };

  const pick = async (picked: File | undefined) => {
    if (!picked) return;
    let problem = checkVisualFile(picked);
    if (!problem && isVideo(picked)) problem = checkVideoLength(await readVideoDuration(picked));
    setError(problem);
    if (input.current) input.current.value = '';
    if (!problem) setFile(picked);
  };

  return (
    <div className="flex flex-col gap-1.5">
      <label htmlFor={id} className="text-fg/90 text-sm font-medium">
        Visuel (facultatif)
      </label>
      <input
        ref={input}
        id={id}
        type="file"
        accept={VISUAL_ACCEPT}
        disabled={disabled}
        aria-describedby={`${id}-hint`}
        onChange={(e) => void pick(e.target.files?.[0])}
        className="text-muted file:bg-surface-3 file:text-fg text-sm file:mr-3 file:rounded-lg file:border-0 file:px-3 file:py-2"
      />
      <p id={`${id}-hint`} className="text-faint text-xs">
        Image JPEG, PNG ou WebP (5 Mo max) ou vidéo MP4 (15 Mo, 60 s max, sans le son). Sans visuel,
        la pochette du morceau est utilisée.
      </p>
      {error && (
        <p role="alert" className="text-danger text-sm">
          {error}
        </p>
      )}
      {file && (
        <div className="bg-surface-2 ring-line flex items-center gap-3 rounded-xl p-2 ring-1">
          {preview &&
            (isVideo(file) ? (
              <video src={preview} muted playsInline className="size-16 rounded-lg object-cover" />
            ) : (
              <img src={preview} alt="" className="size-16 rounded-lg object-cover" />
            ))}
          <p className="min-w-0 flex-1 truncate text-sm">
            {file.name} <span className="text-faint">({formatBytes(file.size)})</span>
          </p>
          <Button
            size="sm"
            variant="ghost"
            icon={<CloseIcon size={16} />}
            disabled={disabled}
            onClick={() => setFile(null)}
            aria-label="Retirer le visuel"
          />
        </div>
      )}
    </div>
  );
}
