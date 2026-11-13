// W-36: sheets opened from a feed post (comments, options menu → report).
import { useCallback, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import type { Comment, Post } from '@/api/types';
import { FlagIcon } from '@/components/ui/icons';
import { Sheet } from '@/components/ui/Sheet';
import { CommentsPanel } from '@/features/comments/CommentsPanel';
import { ReportForm } from '@/features/reports/ReportForm';
import { reportTitle } from '@/features/reports/reportOptions';
import { toast } from '@/stores/toast';

/**
 * Portal out of the feed scroller (its j/k/arrow shortcuts would eat keystrokes typed in the
 * sheet: React events bubble through portals, so they are stopped here, Escape/Tab excepted).
 */
function FeedSheet({
  open,
  title,
  onClose,
  children,
}: {
  open: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
}) {
  if (!open) return null;
  return createPortal(
    <div
      onKeyDown={(e) => {
        if (e.key !== 'Escape' && e.key !== 'Tab') e.stopPropagation();
      }}
    >
      <Sheet open title={title} onClose={onClose}>
        {children}
      </Sheet>
    </div>,
    document.body,
  );
}

/** Comments list + report form, shared by the phone sheet and the desktop side panel. */
export function CommentsBody({
  post,
  onCountDelta,
  reporting,
  setReporting,
}: {
  post: Post;
  onCountDelta: (delta: number) => void;
  reporting: Comment | null;
  setReporting: (c: Comment | null) => void;
}) {
  return (
    <>
      {reporting && (
        <ReportForm
          target={{ type: 'comment', id: reporting.id }}
          onDone={() => setReporting(null)}
        />
      )}
      {/* Kept mounted while reporting so the draft and the scroll survive. */}
      <div hidden={!!reporting}>
        <CommentsPanel postId={post.id} onCountDelta={onCountDelta} onReport={setReporting} />
      </div>
    </>
  );
}

export function CommentsSheet({
  post,
  open,
  onClose,
  onCountDelta,
}: {
  post: Post;
  open: boolean;
  onClose: () => void;
  onCountDelta: (delta: number) => void;
}) {
  const [reporting, setReporting] = useState<Comment | null>(null);
  const close = useCallback(() => {
    setReporting(null);
    onClose();
  }, [onClose]);
  return (
    <FeedSheet
      open={open}
      title={reporting ? reportTitle('comment') : 'Commentaires'}
      onClose={close}
    >
      <CommentsBody
        post={post}
        onCountDelta={onCountDelta}
        reporting={reporting}
        setReporting={setReporting}
      />
    </FeedSheet>
  );
}

async function copyLink(postId: string) {
  const url = `${window.location.origin}/posts/${encodeURIComponent(postId)}`;
  try {
    await navigator.clipboard.writeText(url);
    toast('Lien copié', 'success');
  } catch {
    toast('Impossible de copier le lien.', 'error');
  }
}

export function PostMenuSheet({
  post,
  open,
  onClose,
}: {
  post: Post;
  open: boolean;
  onClose: () => void;
}) {
  const [reporting, setReporting] = useState(false);
  const close = useCallback(() => {
    setReporting(false);
    onClose();
  }, [onClose]);
  const item =
    'hover:bg-surface-2 flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left text-sm font-medium';
  return (
    <FeedSheet open={open} title={reporting ? reportTitle('post') : 'Options'} onClose={close}>
      {reporting ? (
        <ReportForm target={{ type: 'post', id: post.id }} onDone={close} />
      ) : (
        <div className="flex flex-col gap-1">
          <button
            type="button"
            className={item}
            onClick={() => {
              void copyLink(post.id);
              close();
            }}
          >
            Copier le lien
          </button>
          {!post.isOwner && (
            <button type="button" className={item} onClick={() => setReporting(true)}>
              <FlagIcon size={18} /> Signaler ce post
            </button>
          )}
        </div>
      )}
    </FeedSheet>
  );
}
