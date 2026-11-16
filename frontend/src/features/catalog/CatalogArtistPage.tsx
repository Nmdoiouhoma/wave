import { useEffect } from 'react';
import { Link, useParams } from 'react-router';
import { isApiError } from '@/api/errors';
import { useCatalogArtist, useRefetchCatalogArtist } from '@/api/queries/catalog';
import { useCurrentUser } from '@/api/queries/me';
import { Cover } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { NotFoundPage } from '@/routes/NotFoundPage';
import { formatDuration } from '@/lib/format';
import { Attribution, DeezerLink, PreviewButton } from './CatalogParts';
import { preview, usePreview } from './previewPlayer';

const ID_RE = /^dz:[0-9]{1,15}$/;

/** `/discover/artists/:externalId` (W-86): catalogue artist + top-track previews, read-only. */
export function CatalogArtistPage() {
  const me = useCurrentUser();
  const { externalId = '' } = useParams();
  if (!me.features.catalog || !ID_RE.test(externalId)) return <NotFoundPage />;
  return <ArtistView externalId={externalId} />;
}

function ArtistView({ externalId }: { externalId: string }) {
  const query = useCatalogArtist(externalId, true);
  const refetch = useRefetchCatalogArtist(externalId);
  const current = usePreview();
  useEffect(() => () => preview.stop(), []);

  if (query.isPending)
    return (
      <div className="text-muted flex justify-center py-16">
        <Spinner label="Chargement de l’artiste…" />
      </div>
    );
  if (query.isError) {
    const e = query.error;
    if (isApiError(e) && e.status === 404) return <NotFoundPage />;
    return (
      <div className="mx-auto max-w-xl">
        <BackLink />
        <ErrorState error={e} onRetry={() => void query.refetch()} />
      </div>
    );
  }
  const artist = query.data;
  const refreshUrl = async (trackId: string) =>
    (await refetch()).topTracks.find((t) => t.id === trackId)?.previewUrl ?? null;

  return (
    <div className="mx-auto max-w-xl">
      <BackLink />
      <header className="mt-4 flex items-center gap-4">
        <Cover
          url={artist.pictureUrl}
          seed={artist.name}
          className="size-20"
          rounded="rounded-full"
        />
        <div className="min-w-0">
          <h1 className="truncate text-2xl font-bold tracking-tight">{artist.name}</h1>
          {artist.fanCount !== null && (
            <p className="text-muted text-sm">{artist.fanCount.toLocaleString('fr-FR')} fans</p>
          )}
          <DeezerLink url={artist.deezerUrl} />
        </div>
      </header>

      <section aria-label="Titres populaires" className="mt-6">
        <h2 className="text-lg font-semibold">Titres populaires</h2>
        {artist.topTracks.length ? (
          <ul className="mt-2 space-y-2">
            {artist.topTracks.map((t) => {
              const active = current.trackId === t.id;
              const playing =
                active && (current.status === 'playing' || current.status === 'loading');
              return (
                <li
                  key={t.id}
                  className="bg-surface-2 ring-line flex items-center gap-3 rounded-xl p-2 ring-1"
                >
                  <Cover url={t.coverUrl} seed={t.title} className="size-12" />
                  <div className="min-w-0 flex-1">
                    <p className="truncate font-semibold">{t.title}</p>
                    <p className="text-muted truncate text-xs">
                      {t.albumTitle ? `${t.albumTitle} · ` : ''}
                      {formatDuration(t.durationSec * 1000)}
                    </p>
                    {active && current.status === 'error' && (
                      <p role="alert" className="text-danger text-xs">
                        Extrait indisponible pour le moment.
                      </p>
                    )}
                    <DeezerLink url={t.deezerUrl} />
                  </div>
                  <PreviewButton
                    title={t.title}
                    playing={playing}
                    disabled={!t.previewUrl}
                    onClick={() => t.previewUrl && preview.toggle(t.id, t.previewUrl, refreshUrl)}
                  />
                </li>
              );
            })}
          </ul>
        ) : (
          <div className="mt-2">
            <EmptyState title="Pas d’extraits disponibles">
              Le catalogue ne renvoie pas de titres pour cet artiste en ce moment.
            </EmptyState>
          </div>
        )}
        <div className="mt-3">
          <Attribution attribution={artist.attribution} />
        </div>
      </section>
    </div>
  );
}

function BackLink() {
  return (
    <Link to="/discover" className="text-muted text-sm hover:underline">
      ← Découvrir
    </Link>
  );
}
