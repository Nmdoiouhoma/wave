// W-35: `/posts/new[?trackId=]`. Pick a track, choose the 15-60 s segment, optional visual and
// caption, submit (multipart), then poll the post until `ready` (→ `/posts/:id`) or `failed`.
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router';
import { api } from '@/api/instance';
import { isApiError } from '@/api/errors';
import { useCurrentUser } from '@/api/queries/me';
import { useTrack } from '@/api/queries/tracks';
import type { Post, Track } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { ProgressBar } from '@/components/ui/ProgressBar';
import { Spinner } from '@/components/ui/Spinner';
import { ErrorState } from '@/components/ui/States';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { fieldErrors } from '@/api/errors';
import { postErrorMessage, postFailureMessage } from '@/lib/errorMessages';
import { CaptionField } from './CaptionField';
import { ClipSelector } from './ClipSelector';
import { PostAudienceWarning } from './PostAudienceWarning';
import { TrackPicker } from './TrackPicker';
import { VisualField } from './VisualField';
import {
  CAPTION_MAX,
  CLIP_MIN_MS,
  POST_POLL_INTERVAL_MS,
  POST_POLL_TIMEOUT_MS,
  captionLength,
  isPostable,
  maxClipDuration,
} from './postRules';

export function PostComposerPage() {
  const gate = useVerifiedGate('publier un extrait');
  const [params] = useSearchParams();
  const queryTrackId = params.get('trackId');
  const [ignoreQuery, setIgnoreQuery] = useState(false);
  const [chosen, setChosen] = useState<Track | null>(null);
  const fromQuery = useTrack(queryTrackId ?? '', { enabled: !!queryTrackId && !ignoreQuery });

  let body;
  if (gate) {
    body = <Alert tone="info">{gate}</Alert>;
  } else if (chosen) {
    body = <ComposerForm key={chosen.id} track={chosen} onChangeTrack={() => setChosen(null)} />;
  } else if (queryTrackId && !ignoreQuery) {
    if (fromQuery.isPending) body = <Spinner label="Chargement du morceau…" />;
    else if (fromQuery.isError)
      body = (
        <Alert tone="error">
          {isApiError(fromQuery.error) && fromQuery.error.status === 404
            ? 'Ce morceau n’est plus disponible.'
            : postErrorMessage(fromQuery.error)}
        </Alert>
      );
    else if (!fromQuery.data.canClip || !isPostable(fromQuery.data))
      body = (
        <div className="flex flex-col items-start gap-3">
          <Alert tone="error">
            Tu ne peux pas créer d’extrait de ce morceau : son auteur ne l’autorise pas, ou il n’est
            pas prêt.
          </Alert>
          <Button variant="secondary" size="sm" onClick={() => setIgnoreQuery(true)}>
            Choisir un de mes morceaux
          </Button>
        </div>
      );
    else
      body = (
        <ComposerForm
          key={fromQuery.data.id}
          track={fromQuery.data}
          onChangeTrack={() => setIgnoreQuery(true)}
        />
      );
  } else {
    body = (
      <section aria-labelledby="pick-track" className="flex flex-col gap-3">
        <h2 id="pick-track" className="text-lg font-semibold">
          Choisis un morceau
        </h2>
        <TrackPicker selectedId={null} onSelect={setChosen} />
      </section>
    );
  }

  return (
    <div className="mx-auto max-w-xl">
      <h1 className="text-2xl font-bold tracking-tight">Publier un extrait</h1>
      <p className="text-muted mt-1 text-sm">
        Partage 15 à 60 secondes d’un morceau, avec un visuel si tu veux.
      </p>
      <div className="mt-6">{body}</div>
    </div>
  );
}

function ComposerForm({ track, onChangeTrack }: { track: Track; onChangeTrack: () => void }) {
  const trackMs = track.durationMs ?? 0;
  const [clip, setClip] = useState({
    startMs: 0,
    durationMs: Math.min(30_000, maxClipDuration(trackMs)),
  });
  const [caption, setCaption] = useState('');
  const [visual, setVisual] = useState<File | null>(null);
  const [sending, setSending] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const [error, setError] = useState<unknown>(null);
  const [post, setPost] = useState<Post | null>(null);

  const captionTooLong = captionLength(caption) > CAPTION_MAX;
  const busy = sending || post !== null;

  const submit = async (e: React.SyntheticEvent) => {
    e.preventDefault();
    if (busy || captionTooLong || clip.durationMs < CLIP_MIN_MS) return;
    const form = new FormData();
    form.append('trackId', track.id);
    form.append('clipStartMs', String(clip.startMs));
    form.append('clipDurationMs', String(clip.durationMs));
    if (caption.trim()) form.append('caption', caption.trim());
    if (visual) form.append('visual', visual);
    setSending(true);
    setError(null);
    setProgress(visual ? 0 : null);
    try {
      setPost(await api.posts.create(form, { onProgress: (l, t) => setProgress(l / t) }));
    } catch (err) {
      setError(err);
    } finally {
      setSending(false);
    }
  };

  const violations = fieldErrors(error);
  return (
    <form onSubmit={submit} className="flex flex-col gap-6" noValidate>
      <fieldset disabled={busy} className="flex min-w-0 flex-col gap-6">
        <div className="bg-surface-2 ring-line flex items-center gap-3 rounded-xl p-3 ring-1">
          <div className="min-w-0 flex-1">
            <p className="truncate font-medium">{track.title}</p>
            <p className="text-muted truncate text-sm">{track.artist.name}</p>
          </div>
          <Button size="sm" variant="ghost" onClick={onChangeTrack}>
            Changer
          </Button>
        </div>
        <ClipSelector
          trackId={track.id}
          trackMs={trackMs}
          startMs={clip.startMs}
          durationMs={clip.durationMs}
          onChange={setClip}
        />
        <VisualField file={visual} onChange={setVisual} />
        <CaptionField value={caption} onChange={setCaption} />
        {violations.caption && <p className="text-danger text-sm">{violations.caption}</p>}
      </fieldset>
      <PostAudienceWarning track={track} />
      {error !== null && <Alert tone="error">{postErrorMessage(error)}</Alert>}
      {post ? (
        <PostProgress post={post} onRetry={() => setPost(null)} />
      ) : (
        <>
          {sending && visual && (
            <ProgressBar value={progress} label="Envoi du visuel" className="-mt-2" />
          )}
          <Button type="submit" loading={sending} disabled={captionTooLong}>
            Publier
          </Button>
        </>
      )}
    </form>
  );
}

function PostProgress({ post, onRetry }: { post: Post; onRetry: () => void }) {
  const me = useCurrentUser();
  const navigate = useNavigate();
  const [timedOut, setTimedOut] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setTimedOut(true), POST_POLL_TIMEOUT_MS);
    return () => clearTimeout(t);
  }, []);
  const q = useQuery({
    queryKey: ['post', post.id],
    queryFn: () => api.posts.get(post.id),
    initialData: post,
    staleTime: 0,
    refetchInterval: (query) =>
      query.state.data?.status === 'processing' && !timedOut ? POST_POLL_INTERVAL_MS : false,
  });
  const status = q.data.status;
  useEffect(() => {
    if (status === 'ready') void navigate(`/posts/${post.id}`);
  }, [status, post.id, navigate]);

  if (q.isError)
    return (
      <ErrorState
        error={q.error}
        onRetry={() => {
          void q.refetch();
        }}
      />
    );
  if (status === 'failed')
    return (
      <div className="flex flex-col items-start gap-3">
        <Alert tone="error">{postFailureMessage(q.data.failureReason ?? '')}</Alert>
        <Button variant="secondary" onClick={onRetry}>
          Réessayer
        </Button>
      </div>
    );
  if (status === 'processing' && timedOut)
    return (
      <Alert tone="info">
        <div>
          <p className="font-semibold">Toujours en cours</p>
          <p>
            Ton extrait prend plus de temps que prévu. Il apparaîtra sur{' '}
            <Link to={`/u/${me.username}`} className="text-accent underline">
              ton profil
            </Link>{' '}
            dès qu’il sera prêt.
          </p>
        </div>
      </Alert>
    );
  return (
    <div role="status" className="flex items-center gap-3 text-sm">
      <Spinner label="Préparation de ton extrait…" />
      <span>Préparation de ton extrait…</span>
    </div>
  );
}
