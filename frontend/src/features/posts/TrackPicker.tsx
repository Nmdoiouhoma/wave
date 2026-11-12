// W-35: own tracks that can carry a post (ready, visible, at least 15 s), newest first.
import { useCurrentUser } from '@/api/queries/me';
import { useUserTracks } from '@/api/queries/users';
import type { Track } from '@/api/types';
import { ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { Cover } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { MusicIcon } from '@/components/ui/icons';
import { formatDuration } from '@/lib/format';
import { isPostable } from './postRules';

export function TrackPicker({
  selectedId,
  onSelect,
}: {
  selectedId: string | null;
  onSelect: (t: Track) => void;
}) {
  const me = useCurrentUser();
  const tracks = useUserTracks(me.username);
  if (tracks.isPending) return <Spinner label="Chargement de tes morceaux…" />;
  if (tracks.isError) return <ErrorState error={tracks.error} onRetry={() => tracks.refetch()} />;
  const items = tracks.data.pages.flatMap((p) => p.items).filter(isPostable);
  if (items.length === 0 && !tracks.hasNextPage)
    return (
      <EmptyState icon={<MusicIcon size={28} />} title="Aucun morceau prêt">
        <p>Importe un morceau d’au moins 15 secondes pour en partager un extrait.</p>
        <ButtonLink to="/upload" size="sm" variant="secondary" className="mt-3">
          Importer un morceau
        </ButtonLink>
      </EmptyState>
    );
  return (
    <div>
      <div role="radiogroup" aria-label="Morceau à partager" className="flex flex-col gap-2">
        {items.map((t) => (
          <label
            key={t.id}
            className={cx(
              'bg-surface-2 flex cursor-pointer items-center gap-3 rounded-xl p-2 pr-4 ring-1 transition has-[:focus-visible]:ring-2',
              selectedId === t.id ? 'ring-accent ring-2' : 'ring-line',
            )}
          >
            <input
              type="radio"
              name="track"
              checked={selectedId === t.id}
              onChange={() => onSelect(t)}
              className="sr-only"
            />
            <Cover url={t.coverUrl} seed={t.id} className="size-12" />
            <span className="min-w-0 flex-1">
              <span className="block truncate text-sm font-medium">{t.title}</span>
              <span className="text-muted block truncate text-xs">{t.artist.name}</span>
            </span>
            <span className="text-faint text-xs tabular-nums">{formatDuration(t.durationMs)}</span>
          </label>
        ))}
      </div>
      <InfiniteSentinel
        hasMore={!!tracks.hasNextPage}
        loading={tracks.isFetchingNextPage}
        onLoadMore={() => void tracks.fetchNextPage()}
      />
    </div>
  );
}
