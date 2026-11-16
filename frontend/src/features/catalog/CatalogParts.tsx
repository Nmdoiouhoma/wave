import { Link } from 'react-router';
import type { CatalogArtistSummary, CatalogAttribution } from '@/api/types';
import { Cover } from '@/components/ui/Media';
import { PauseIcon, PlayIcon } from '@/components/ui/icons';

const DEFAULT_ATTRIBUTION: CatalogAttribution = {
  text: 'Données et extraits : Deezer',
  url: 'https://www.deezer.com',
};

/** Must stay visible next to every catalogue block (ADR 014). */
export function Attribution({ attribution }: { attribution?: CatalogAttribution }) {
  const a = attribution ?? DEFAULT_ATTRIBUTION;
  return (
    <p className="text-faint text-xs">
      <a href={a.url} target="_blank" rel="noopener noreferrer" className="hover:underline">
        {a.text}
      </a>
    </p>
  );
}

export function DeezerLink({ url, className }: { url: string; className?: string }) {
  return (
    <a
      href={url}
      target="_blank"
      rel="noopener noreferrer"
      className={className ?? 'text-accent text-xs font-medium hover:underline'}
    >
      Écouter sur Deezer
    </a>
  );
}

export function CatalogArtistRow({ artist }: { artist: CatalogArtistSummary }) {
  return (
    <li className="bg-surface-2 ring-line flex items-center gap-3 rounded-xl p-2 ring-1">
      <Link
        to={`/discover/artists/${encodeURIComponent(artist.id)}`}
        className="flex min-w-0 flex-1 items-center gap-3"
      >
        <Cover
          url={artist.pictureUrl}
          seed={artist.name}
          className="size-12"
          rounded="rounded-full"
        />
        <span className="min-w-0">
          <span className="block truncate font-semibold">{artist.name}</span>
          {artist.fanCount !== null && (
            <span className="text-muted block text-xs">
              {artist.fanCount.toLocaleString('fr-FR')} fans
            </span>
          )}
        </span>
      </Link>
      <DeezerLink
        url={artist.deezerUrl}
        className="text-accent shrink-0 pr-2 text-xs hover:underline"
      />
    </li>
  );
}

export function PreviewButton({
  title,
  playing,
  disabled,
  onClick,
}: {
  title: string;
  playing: boolean;
  disabled?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      aria-label={`${playing ? 'Arrêter' : 'Écouter'} l’extrait de ${title}`}
      aria-pressed={playing}
      className="bg-accent text-accent-contrast inline-flex size-10 shrink-0 items-center justify-center rounded-full disabled:opacity-40"
    >
      {playing ? <PauseIcon size={18} /> : <PlayIcon size={18} />}
    </button>
  );
}
