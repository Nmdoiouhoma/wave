import { Link } from 'react-router';
import type { Playlist } from '@/api/types';
import { LockIcon } from '@/components/ui/icons';
import { Cover } from '@/components/ui/Media';

export function PlaylistGrid({ playlists, label }: { playlists: Playlist[]; label: string }) {
  return (
    <ul aria-label={label} className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
      {playlists.map((p) => (
        <li key={p.id}>
          <Link
            to={`/p/${p.id}`}
            className="hover:bg-surface-2 flex flex-col gap-2 rounded-2xl p-2 transition"
          >
            <Cover
              url={p.coverUrl}
              seed={p.id}
              rounded="rounded-xl"
              className="aspect-square w-full"
            />
            <span className="min-w-0 px-1">
              <span className="flex items-center gap-1.5 font-semibold">
                <span className="truncate">{p.name}</span>
                {!p.isPublic && (
                  <>
                    <LockIcon size={13} className="text-muted shrink-0" />
                    <span className="sr-only">(privée)</span>
                  </>
                )}
              </span>
              <span className="text-muted block truncate text-xs">
                {p.isOwner ? '' : `${p.owner.displayName} · `}
                {p.itemCount} morceau{p.itemCount > 1 ? 'x' : ''}
              </span>
            </span>
          </Link>
        </li>
      ))}
    </ul>
  );
}
