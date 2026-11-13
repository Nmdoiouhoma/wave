import { useMutation } from '@tanstack/react-query';
import { useCallback, useEffect, useRef, useState, type SVGProps } from 'react';
import { Link } from 'react-router';
import type { LikeState } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { HeartIcon, MoreIcon, MusicIcon, PlayIcon } from '@/components/ui/icons';
import { Avatar, Cover } from '@/components/ui/Media';
import { WIDE_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { errorMessage } from '@/lib/errorMessages';
import { formatCount } from '@/lib/format';
import { toast } from '@/stores/toast';
import { feedApi, type Post } from './feedApi';
import type { ClipStatus } from './feedPlayback';
import { focusSideComment } from './focusSideComment';
import { CommentsSheet, PostMenuSheet } from './PostSheets';

/** Second tap within this delay = double tap (like); otherwise the tap toggles playback. */
const DOUBLE_TAP_MS = 250;

function CommentIcon(p: SVGProps<SVGSVGElement>) {
  return (
    <svg
      width={26}
      height={26}
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={2}
      strokeLinejoin="round"
      aria-hidden
      {...p}
    >
      <path d="M4 5h16v11H9l-5 4z" />
    </svg>
  );
}

function usePostLike(post: Post) {
  const [override, setOverride] = useState<LikeState | null>(null);
  const state = override ?? { liked: post.likedByMe, likeCount: post.likeCount };
  const mutation = useMutation({
    mutationFn: (like: boolean) => (like ? feedApi.like(post.id) : feedApi.unlike(post.id)),
    onMutate: (like) => {
      const previous = override;
      setOverride({ liked: like, likeCount: Math.max(0, state.likeCount + (like ? 1 : -1)) });
      return { previous };
    },
    onError: (e, _like, ctx) => {
      setOverride(ctx?.previous ?? null);
      toast(errorMessage(e), 'error');
    },
    onSuccess: (server) => setOverride(server),
  });
  const set = (like: boolean) => {
    if (!mutation.isPending && like !== state.liked) mutation.mutate(like);
  };
  return { state, set };
}

function Visual({ post, near, playing }: { post: Post; near: boolean; playing: boolean }) {
  const video = useRef<HTMLVideoElement>(null);
  const v = post.visual;
  useEffect(() => {
    const el = video.current;
    if (!el) return;
    try {
      if (playing) void el.play()?.catch(() => {});
      else el.pause();
    } catch {
      // Media playback unsupported (tests): the poster stays.
    }
  }, [playing]);

  if (!near) return null;
  if (v?.type === 'video')
    return (
      <video
        ref={video}
        src={v.url}
        poster={v.posterUrl ?? undefined}
        muted
        loop
        playsInline
        preload="auto"
        aria-hidden
        className="absolute inset-0 size-full object-cover"
      />
    );
  if (v?.type === 'image')
    return <img src={v.url} alt="" className="absolute inset-0 size-full object-cover" />;
  return (
    <div className="absolute inset-0 flex items-center justify-center">
      <Cover
        url={post.track.coverUrl}
        seed={post.track.id}
        className="aspect-square w-3/4 max-w-sm shadow-2xl"
        rounded="rounded-2xl"
      />
    </div>
  );
}

function StatusBadge({ status }: { status: ClipStatus | null }) {
  if (status === 'paused')
    return (
      <span className="flex size-20 items-center justify-center rounded-full bg-black/40 text-white">
        <PlayIcon size={40} />
      </span>
    );
  if (status === 'blocked')
    return (
      <span className="flex items-center gap-2 rounded-full bg-black/60 px-4 py-2 text-sm font-semibold text-white">
        <PlayIcon size={18} /> Touche pour écouter
      </span>
    );
  if (status === 'unavailable')
    return (
      <span className="rounded-full bg-black/60 px-4 py-2 text-sm font-semibold text-white">
        Extrait indisponible
      </span>
    );
  return null;
}

export function FeedItem({
  post,
  active,
  near,
  status,
  onTogglePlay,
}: {
  post: Post;
  active: boolean;
  /** Within the window around the active post: media elements are mounted. */
  near: boolean;
  status: ClipStatus | null;
  onTogglePlay: () => void;
}) {
  const like = usePostLike(post);
  const wide = useMediaQuery(WIDE_QUERY);
  // W-36: optimistic comment count (+1 on post, −1 on delete, reverted on error).
  const [commentDelta, setCommentDelta] = useState(0);
  const [sheet, setSheet] = useState<'comments' | 'menu' | null>(null);
  const closeSheet = useCallback(() => setSheet(null), []);
  const onCommentDelta = useCallback((d: number) => setCommentDelta((n) => n + d), []);
  const tapTimer = useRef<number | null>(null);
  const [burst, setBurst] = useState(0);
  useEffect(
    () => () => {
      if (tapTimer.current !== null) window.clearTimeout(tapTimer.current);
    },
    [],
  );

  const onTap = () => {
    if (tapTimer.current !== null) {
      window.clearTimeout(tapTimer.current);
      tapTimer.current = null;
      like.set(true);
      setBurst((n) => n + 1);
      return;
    }
    tapTimer.current = window.setTimeout(() => {
      tapTimer.current = null;
      onTogglePlay();
    }, DOUBLE_TAP_MS);
  };

  const playing = active && (status === 'playing' || status === 'loading' || status === 'blocked');
  const title = post.track.title;
  const commentCount = Math.max(0, post.commentCount + commentDelta);
  const likeLabel = like.state.liked ? 'Je n’aime plus' : 'J’aime';

  return (
    <article
      aria-label={`Extrait de ${post.author.displayName} : ${title}`}
      aria-current={active ? 'true' : undefined}
      className="relative h-full w-full snap-start snap-always overflow-hidden bg-black text-white"
    >
      <Visual post={post} near={near} playing={playing} />
      <button
        type="button"
        onClick={onTap}
        aria-label={status === 'playing' ? 'Mettre en pause' : 'Lire l’extrait'}
        className="absolute inset-0 z-0 flex items-center justify-center bg-gradient-to-b from-black/30 via-transparent to-black/70"
      >
        {active && <StatusBadge status={status} />}
        {burst > 0 && (
          <HeartIcon
            key={burst}
            filled
            size={96}
            className="text-like pointer-events-none absolute animate-ping [animation-iteration-count:1]"
          />
        )}
      </button>

      <div className="pointer-events-none absolute inset-x-0 bottom-0 z-10 flex items-end gap-3 p-4 pb-6">
        <div className="pointer-events-auto min-w-0 flex-1 space-y-2">
          <Link
            to={`/u/${encodeURIComponent(post.author.username)}`}
            className="flex w-fit items-center gap-2 font-semibold"
          >
            <Avatar
              url={post.author.avatarUrl}
              name={post.author.displayName}
              seed={post.author.id}
              className="size-9 text-xs ring-2 ring-white/70"
            />
            {post.author.displayName}
          </Link>
          {post.caption && (
            <p className="line-clamp-3 text-sm break-words whitespace-pre-line">{post.caption}</p>
          )}
          <Link
            to={`/t/${encodeURIComponent(post.track.id)}`}
            className="flex w-fit max-w-full items-center gap-2 text-sm text-white/90"
          >
            <MusicIcon size={16} className="shrink-0" />
            <span className="truncate">
              {title} · {post.track.artist.name}
            </span>
          </Link>
          {post.track.canClip && (
            <Link
              to={`/posts/new?trackId=${encodeURIComponent(post.track.id)}`}
              className="inline-flex rounded-full bg-white/15 px-3 py-1.5 text-xs font-semibold backdrop-blur hover:bg-white/25"
            >
              Utiliser ce son
            </Link>
          )}
        </div>

        <div className="pointer-events-auto flex flex-col items-center gap-4">
          <button
            type="button"
            aria-pressed={like.state.liked}
            aria-label={likeLabel}
            onClick={() => like.set(!like.state.liked)}
            className="flex flex-col items-center gap-0.5 text-xs font-semibold"
          >
            <HeartIcon
              size={30}
              filled={like.state.liked}
              className={cx(like.state.liked && 'text-like')}
            />
            <span>{formatCount(like.state.likeCount)}</span>
          </button>
          <button
            type="button"
            aria-label={`Commentaires, ${commentCount}`}
            aria-haspopup={wide ? undefined : 'dialog'}
            // FB-30: lg+ focuses the side panel's comment field, below it opens the sheet.
            onClick={() => (wide && focusSideComment() ? undefined : setSheet('comments'))}
            className="flex flex-col items-center gap-0.5 text-xs font-semibold"
          >
            <CommentIcon />
            <span>{formatCount(commentCount)}</span>
          </button>
          <button
            type="button"
            aria-label="Plus d’options"
            aria-haspopup="dialog"
            onClick={() => setSheet('menu')}
            className="flex size-8 items-center justify-center"
          >
            <MoreIcon size={24} />
          </button>
        </div>
      </div>
      <CommentsSheet
        post={post}
        open={sheet === 'comments'}
        onClose={closeSheet}
        onCountDelta={onCommentDelta}
      />
      <PostMenuSheet post={post} open={sheet === 'menu'} onClose={closeSheet} />
    </article>
  );
}
