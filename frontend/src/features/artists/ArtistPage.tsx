import { Link, useParams } from 'react-router';
import { useArtist, useArtistTracks } from '@/api/queries/artists';
import { ButtonLink } from '@/components/ui/Button';
import { EditIcon, MusicIcon } from '@/components/ui/icons';
import { Avatar } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { formatCount, hueOf } from '@/lib/format';
import { PlayAllButton, TrackList } from '@/features/tracks/TrackList';

export function ArtistPage() {
  const { slug = '' } = useParams();
  const artist = useArtist(slug);
  const tracks = useArtistTracks(slug);

  if (artist.isPending) return <PageSpinner />;
  if (artist.isError)
    return <ErrorState error={artist.error} onRetry={() => void artist.refetch()} />;
  const a = artist.data;
  const items = tracks.data?.pages.flatMap((p) => p.items) ?? [];
  const hue = hueOf(a.id);

  return (
    <div className="mx-auto max-w-3xl">
      <header
        className="-mx-4 -mt-4 flex flex-col items-center gap-4 px-4 pt-10 pb-6 text-center sm:flex-row sm:items-end sm:text-left md:-mx-8 md:px-8"
        style={{ background: `linear-gradient(180deg, hsl(${hue} 60% 30% / 0.55), transparent)` }}
      >
        <Avatar
          url={a.avatarUrl}
          name={a.name}
          seed={a.id}
          className="size-32 text-4xl shadow-2xl shadow-black/50 sm:size-40"
        />
        <div className="min-w-0 flex-1">
          <p className="text-muted text-xs font-semibold tracking-widest uppercase">Artiste</p>
          <h1 className="mt-1 text-3xl font-bold tracking-tight break-words sm:text-5xl">
            {a.name}
          </h1>
          <p className="text-muted mt-2 text-sm">
            {formatCount(a.trackCount)} titre{a.trackCount > 1 ? 's' : ''} · géré par{' '}
            <Link to={`/u/${a.owner.username}`} className="text-fg font-medium hover:underline">
              {a.owner.displayName}
            </Link>
            {a.moderation === 'hidden' && (
              <span className="text-warning ml-2">· masqué par la modération</span>
            )}
          </p>
        </div>
      </header>

      {a.bio && <p className="text-fg/85 mt-4 text-sm whitespace-pre-line">{a.bio}</p>}

      <div className="mt-6 flex flex-wrap gap-2">
        <PlayAllButton tracks={items} />
        {a.isOwner && (
          <ButtonLink to={`/me/artists?edit=${a.slug}`} variant="secondary">
            <EditIcon size={16} /> Modifier
          </ButtonLink>
        )}
      </div>

      <section className="mt-6" aria-labelledby="artist-tracks">
        <h2 id="artist-tracks" className="mb-2 text-lg font-semibold">
          Titres
        </h2>
        {tracks.isPending ? (
          <PageSpinner />
        ) : tracks.isError ? (
          <ErrorState error={tracks.error} onRetry={() => void tracks.refetch()} />
        ) : items.length ? (
          <TrackList
            label={`Titres de ${a.name}`}
            tracks={items}
            showArtist={false}
            hasMore={tracks.hasNextPage}
            loadingMore={tracks.isFetchingNextPage}
            onLoadMore={() => void tracks.fetchNextPage()}
          />
        ) : (
          <EmptyState icon={<MusicIcon size={28} />} title="Aucun titre pour l’instant" />
        )}
      </section>
    </div>
  );
}
