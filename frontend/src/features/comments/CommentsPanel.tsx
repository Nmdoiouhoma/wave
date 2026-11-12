// W-36: comments of a post (newest first): infinite list, composer, delete, report entry point.
import {
  useInfiniteQuery,
  useMutation,
  useQueryClient,
  type InfiniteData,
} from '@tanstack/react-query';
import { useState } from 'react';
import { Link } from 'react-router';
import { isApiError } from '@/api/errors';
import { api } from '@/api/instance';
import type { Comment, CommentPage } from '@/api/types';
import { Alert } from '@/components/ui/Alert';
import { Button } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { FlagIcon, MoreIcon, TrashIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Avatar } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { ErrorState } from '@/components/ui/States';
import { useVerifiedGate } from '@/features/auth/useVerifiedGate';
import { errorMessage, retryHint } from '@/lib/errorMessages';
import { useSession } from '@/stores/session';
import { toast } from '@/stores/toast';
import { COMMENT_MAX, commentsKey, timeAgo } from './commentUtils';

type Pages = InfiniteData<CommentPage, string | null>;

function composerError(e: unknown): string {
  if (isApiError(e)) {
    if (e.code === 'rate_limited') return `Tu commentes trop vite. ${retryHint(e.retryAfter)}`;
    if (e.status === 404) return 'Ce post n’est plus disponible.';
    if (e.code === 'validation_failed')
      return e.violations[0]?.message ?? 'Le commentaire doit faire 1 à 500 caractères.';
  }
  return errorMessage(e);
}

function Composer({ postId, onCountDelta }: { postId: string; onCountDelta: (d: number) => void }) {
  const qc = useQueryClient();
  const [body, setBody] = useState('');
  const gate = useVerifiedGate('commenter');
  const trimmed = body.trim();
  const mutation = useMutation({
    mutationFn: (text: string) => api.comments.create(postId, text),
    onMutate: () => onCountDelta(1),
    onError: () => onCountDelta(-1),
    onSuccess: (comment) => {
      setBody('');
      qc.setQueryData<Pages>(commentsKey(postId), (data) =>
        data
          ? {
              ...data,
              pages: data.pages.map((p, i) =>
                i === 0 ? { ...p, items: [comment, ...p.items] } : p,
              ),
            }
          : data,
      );
    },
  });
  const tooLong = trimmed.length > COMMENT_MAX;

  return (
    <form
      noValidate
      onSubmit={(e) => {
        e.preventDefault();
        if (trimmed && !tooLong && !gate && !mutation.isPending) mutation.mutate(trimmed);
      }}
      className="flex flex-col gap-2"
    >
      <label className="sr-only" htmlFor={`comment-${postId}`}>
        Ton commentaire
      </label>
      <textarea
        id={`comment-${postId}`}
        value={body}
        onChange={(e) => setBody(e.target.value)}
        placeholder="Ajoute un commentaire…"
        rows={2}
        disabled={!!gate}
        aria-invalid={tooLong || undefined}
        className="bg-surface-2 text-fg ring-line placeholder:text-faint focus:ring-accent aria-[invalid=true]:ring-danger w-full resize-none rounded-xl px-4 py-3 text-sm ring-1 focus:ring-2 focus:outline-none disabled:opacity-60"
      />
      <div className="flex items-center justify-between gap-3">
        <span className={cx('text-xs', tooLong ? 'text-danger font-medium' : 'text-faint')}>
          {trimmed.length} / {COMMENT_MAX}
        </span>
        <Button
          type="submit"
          size="sm"
          disabled={!trimmed || tooLong || !!gate}
          loading={mutation.isPending}
        >
          Publier
        </Button>
      </div>
      {gate && <Alert tone="warning">{gate}</Alert>}
      {mutation.isError && <Alert tone="error">{composerError(mutation.error)}</Alert>}
    </form>
  );
}

function CommentRow({
  comment,
  canReport,
  onDelete,
  onReport,
}: {
  comment: Comment;
  /** False until the session is known, and on one's own comment (the API answers 422). */
  canReport: boolean;
  onDelete: () => void;
  onReport: () => void;
}) {
  const [menu, setMenu] = useState(false);
  const hasActions = comment.canDelete || canReport;
  const a = comment.author;
  const profile = `/u/${encodeURIComponent(a.username)}`;
  return (
    <li className="flex gap-3 py-3">
      <Link to={profile} className="shrink-0" tabIndex={-1} aria-hidden>
        <Avatar url={a.avatarUrl} name={a.displayName} seed={a.id} className="size-9 text-xs" />
      </Link>
      <div className="min-w-0 flex-1">
        <p className="text-xs">
          <Link to={profile} className="text-fg font-semibold hover:underline">
            {a.displayName}
          </Link>
          <span className="text-faint"> · {timeAgo(comment.createdAt)}</span>
          {comment.moderation === 'hidden' && (
            <span className="text-warning"> · masqué par la modération</span>
          )}
        </p>
        <p className="text-fg/90 mt-0.5 text-sm break-words whitespace-pre-line">{comment.body}</p>
        {menu && (
          <div className="mt-2 flex flex-wrap gap-2">
            {comment.canDelete && (
              <Button
                size="sm"
                variant="danger"
                icon={<TrashIcon size={14} />}
                onClick={() => {
                  setMenu(false);
                  onDelete();
                }}
              >
                Supprimer
              </Button>
            )}
            {canReport && (
              <Button
                size="sm"
                variant="secondary"
                icon={<FlagIcon size={14} />}
                onClick={() => {
                  setMenu(false);
                  onReport();
                }}
              >
                Signaler
              </Button>
            )}
          </div>
        )}
      </div>
      {hasActions && (
        <button
          type="button"
          aria-label={`Options du commentaire de ${a.displayName}`}
          aria-expanded={menu}
          onClick={() => setMenu((v) => !v)}
          className="text-muted hover:text-fg flex size-8 shrink-0 items-center justify-center rounded-full"
        >
          <MoreIcon size={18} />
        </button>
      )}
    </li>
  );
}

export function CommentsPanel({
  postId,
  onCountDelta,
  onReport,
}: {
  postId: string;
  /** Optimistic change of the post's `commentCount` (+1 posted, −1 deleted, reverted on error). */
  onCountDelta: (delta: number) => void;
  onReport: (comment: Comment) => void;
}) {
  const qc = useQueryClient();
  const meId = useSession((s) => s.user?.id ?? null);
  const key = commentsKey(postId);
  const query = useInfiniteQuery({
    queryKey: key,
    queryFn: ({ pageParam }) => api.comments.list(postId, { cursor: pageParam }),
    initialPageParam: null as string | null,
    getNextPageParam: (last) => last.nextCursor,
  });

  const remove = useMutation({
    mutationFn: (c: Comment) => api.comments.remove(c.id),
    onMutate: async (c) => {
      await qc.cancelQueries({ queryKey: key });
      const previous = qc.getQueryData<Pages>(key);
      qc.setQueryData<Pages>(key, (data) =>
        data
          ? {
              ...data,
              pages: data.pages.map((p) => ({
                ...p,
                items: p.items.filter((i) => i.id !== c.id),
              })),
            }
          : data,
      );
      onCountDelta(-1);
      return { previous };
    },
    onError: (e, _c, ctx) => {
      // 404: already gone (deleted elsewhere or moderated), the optimistic removal stands.
      if (isApiError(e) && e.status === 404) return;
      if (ctx?.previous) qc.setQueryData(key, ctx.previous);
      onCountDelta(1);
      toast(errorMessage(e), 'error');
    },
    onSuccess: () => toast('Commentaire supprimé'),
  });

  const items = query.data?.pages.flatMap((p) => p.items) ?? [];

  return (
    <div className="flex flex-col gap-3">
      <Composer postId={postId} onCountDelta={onCountDelta} />
      {query.isPending ? (
        <div className="flex justify-center py-6">
          <Spinner />
        </div>
      ) : query.isError ? (
        isApiError(query.error) && query.error.status === 404 ? (
          <p className="text-muted py-6 text-center text-sm">Ce post n’est plus disponible.</p>
        ) : (
          <ErrorState error={query.error} onRetry={() => void query.refetch()} />
        )
      ) : items.length === 0 ? (
        <p className="text-muted py-6 text-center text-sm">
          Aucun commentaire pour l’instant. Lance la conversation !
        </p>
      ) : (
        <>
          <ul aria-label="Commentaires" className="divide-line divide-y">
            {items.map((c) => (
              <CommentRow
                key={c.id}
                comment={c}
                canReport={meId !== null && c.author.id !== meId}
                onDelete={() => remove.mutate(c)}
                onReport={() => onReport(c)}
              />
            ))}
          </ul>
          <InfiniteSentinel
            hasMore={!!query.hasNextPage}
            loading={query.isFetchingNextPage}
            onLoadMore={() => void query.fetchNextPage({ cancelRefetch: false })}
          />
        </>
      )}
    </div>
  );
}
