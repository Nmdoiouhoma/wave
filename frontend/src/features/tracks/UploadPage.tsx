import { useSearchParams } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { useUserArtists } from '@/api/queries/users';
import { MultiUploader } from './MultiUploader';

export function UploadPage() {
  const me = useCurrentUser();
  const [params] = useSearchParams();
  const artists = useUserArtists(me.username);
  // `?artist=<slug>` preselects one of my artists.
  const preselected = artists.data?.find((a) => a.slug === params.get('artist'))?.id;

  return (
    <div className="mx-auto max-w-xl">
      <h1 className="text-2xl font-bold tracking-tight">Importer des morceaux</h1>
      <p className="text-muted mt-1 text-sm">
        Un ou plusieurs fichiers d’un coup : chacun devient un titre de ton artiste.
      </p>
      <div className="mt-6">
        {artists.isPending ? null : (
          <MultiUploader key={preselected ?? 'none'} initialArtistId={preselected} />
        )}
      </div>
    </div>
  );
}
