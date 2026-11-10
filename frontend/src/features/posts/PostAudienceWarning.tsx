// FB-18 (W-48): shown above "Publier" in the post composer (W-35 mounts it). A post on the
// owner's own track shares the track's audience, live (ADR 012 §5).
import type { Track } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { AUDIENCE_TARGET } from '@/features/tracks/audience';

export function PostAudienceWarning({ track }: { track: Pick<Track, 'isOwner' | 'visibility'> }) {
  const v = track.visibility;
  if (!track.isOwner || !v || v === 'public') return null;
  return (
    <Alert tone="info">
      <p className="font-semibold">Visible seulement par {AUDIENCE_TARGET[v]}</p>
      <p className="text-xs">Si tu changes l’audience du morceau, l’extrait suivra.</p>
    </Alert>
  );
}
