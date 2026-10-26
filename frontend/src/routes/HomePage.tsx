import { Link } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { useUserArtists, useUserTracks } from '@/api/queries/users';
import { ButtonLink } from '@/components/ui/Button';
import { MicIcon, MusicIcon, UploadIcon, UserIcon } from '@/components/ui/icons';
import { Avatar } from '@/components/ui/Media';
import { PageSpinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { PlayAllButton, TrackList } from '@/features/tracks/TrackList';

function greeting(date = new Date()): string {
  const h = date.getHours();
  if (h < 5 || h >= 18) return 'Bonsoir';
  return 'Bonjour';
}

export function HomePage() {
  const me = useCurrentUser();
  const tracks = useUserTracks(me.username);
  const artists = useUserArtists(me.username);
  const items = tracks.data?.pages.flatMap((p) => p.items) ?? [];
  const firstName = me.displayName.split(' ')[0];

  const shortcuts = [
    { to: '/upload', label: 'Importer des morceaux', icon: UploadIcon },
    { to: '/me/artists/new', label: 'Créer un artiste', icon: MicIcon },
    { to: `/u/${me.username}`, label: 'Mon profil', icon: UserIcon },
  ];

  return (
    <div className="mx-auto flex max-w-4xl flex-col gap-8">
      <section>
        <h1 className="text-3xl font-bold tracking-tight">
          {greeting()}, <span className="text-wave">{firstName}</span>
        </h1>
        <p className="text-muted mt-1">Votre musique, vos artistes, au même endroit.</p>
        <div className="mt-5 grid grid-cols-3 gap-2 sm:gap-3">
          {shortcuts.map(({ to, label, icon: Icon }) => (
            <Link
              key={to}
              to={to}
              className="bg-surface ring-line hover:bg-surface-2 flex flex-col items-start gap-3 rounded-2xl p-3 ring-1 transition sm:p-4"
            >
              <span className="bg-wave flex size-9 items-center justify-center rounded-xl text-white">
                <Icon size={18} />
              </span>
              <span className="text-sm leading-tight font-semibold">{label}</span>
            </Link>
          ))}
        </div>
      </section>

      {!!artists.data?.length && (
        <section aria-labelledby="home-artists">
          <h2 id="home-artists" className="mb-3 text-lg font-semibold">
            Vos artistes
          </h2>
          <ul className="-mx-4 flex gap-4 overflow-x-auto px-4 pb-2 md:mx-0 md:px-0">
            {artists.data.map((a) => (
              <li key={a.id} className="shrink-0">
                <Link
                  to={`/a/${a.slug}`}
                  className="flex w-24 flex-col items-center gap-2 text-center"
                >
                  <Avatar url={a.avatarUrl} name={a.name} seed={a.id} className="size-20 text-xl" />
                  <span className="w-full truncate text-sm font-medium">{a.name}</span>
                </Link>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section aria-labelledby="home-tracks">
        <div className="mb-3 flex items-center justify-between gap-3">
          <h2 id="home-tracks" className="text-lg font-semibold">
            Vos titres
          </h2>
          {items.length > 0 && <PlayAllButton tracks={items} />}
        </div>
        {tracks.isPending ? (
          <PageSpinner />
        ) : tracks.isError ? (
          <ErrorState error={tracks.error} onRetry={() => void tracks.refetch()} />
        ) : items.length ? (
          <TrackList
            label="Vos titres"
            tracks={items}
            hasMore={tracks.hasNextPage}
            loadingMore={tracks.isFetchingNextPage}
            onLoadMore={() => void tracks.fetchNextPage()}
          />
        ) : (
          <EmptyState icon={<MusicIcon size={28} />} title="Votre bibliothèque est vide">
            <p>Importez votre premier titre pour l’écouter partout.</p>
            <ButtonLink to="/upload" size="sm" className="mt-3">
              <UploadIcon size={16} /> Importer
            </ButtonLink>
          </EmptyState>
        )}
      </section>
    </div>
  );
}
