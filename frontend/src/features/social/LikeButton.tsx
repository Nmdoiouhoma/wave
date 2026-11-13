import { useLikeState, useToggleLike } from '@/api/queries/social';
import type { LikeState } from '@/api/types';
import { cx } from '@/components/ui/cx';
import { HeartIcon } from '@/components/ui/icons';
import { errorMessage } from '@/lib/errorMessages';
import { formatCount } from '@/lib/format';
import { toast } from '@/stores/toast';

/** Optimistic like (track or playlist) with rollback; state shared app-wide (stores/social). */
export function LikeButton({
  kind,
  id,
  title,
  server,
  showCount = false,
  className,
}: {
  kind: 'track' | 'playlist';
  id: string;
  title: string;
  server: LikeState;
  showCount?: boolean;
  className?: string;
}) {
  const state = useLikeState(kind, id, server);
  const toggle = useToggleLike(kind);
  const label = state.liked
    ? `Retirer « ${title} » de tes coups de cœur`
    : `Ajouter « ${title} » à tes coups de cœur`;
  return (
    <button
      type="button"
      aria-pressed={state.liked}
      aria-label={label}
      title={label}
      disabled={toggle.isPending}
      onClick={() =>
        toggle.mutate(
          { id, like: !state.liked, current: state },
          { onError: (e) => toast(errorMessage(e), 'error') },
        )
      }
      className={cx(
        'hover:bg-surface-2 inline-flex h-10 min-w-10 shrink-0 items-center justify-center gap-1.5 rounded-full px-2 transition disabled:cursor-wait',
        state.liked ? 'text-like' : 'text-muted hover:text-fg',
        className,
      )}
    >
      <HeartIcon size={20} filled={state.liked} />
      {showCount && <span className="text-sm tabular-nums">{formatCount(state.likeCount)}</span>}
    </button>
  );
}
