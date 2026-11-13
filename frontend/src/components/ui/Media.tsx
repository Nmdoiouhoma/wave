import { useState } from 'react';
import { hueOf, initials } from '@/lib/format';
import { cx } from './cx';
import { MusicIcon } from './icons';

function gradient(seed: string) {
  const h = hueOf(seed);
  return `hsl(${h} 75% 86%)`;
}

function useImageOk(url: string | null) {
  const [failed, setFailed] = useState<string | null>(null);
  return { show: !!url && failed !== url, onError: () => setFailed(url) };
}

export function Avatar({
  url,
  name,
  seed,
  className,
}: {
  url: string | null;
  name: string;
  seed: string;
  className?: string;
}) {
  const img = useImageOk(url);
  return (
    <span
      className={cx(
        'relative inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full font-semibold text-[#16161a] select-none',
        className ?? 'size-10 text-sm',
      )}
      style={{ background: gradient(seed) }}
    >
      {img.show ? (
        <img src={url!} alt="" className="size-full object-cover" onError={img.onError} />
      ) : (
        <span aria-hidden>{initials(name)}</span>
      )}
    </span>
  );
}

export function Cover({
  url,
  seed,
  className,
  rounded = 'rounded-lg',
}: {
  url: string | null;
  seed: string;
  className?: string;
  rounded?: string;
}) {
  const img = useImageOk(url);
  return (
    <span
      className={cx(
        'relative inline-flex shrink-0 items-center justify-center overflow-hidden',
        rounded,
        className ?? 'size-12',
      )}
      style={{ background: gradient(seed) }}
    >
      {img.show ? (
        <img src={url!} alt="" className="size-full object-cover" onError={img.onError} />
      ) : (
        <MusicIcon className="size-2/5 text-[#16161a]/70" />
      )}
    </span>
  );
}
