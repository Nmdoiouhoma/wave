// FB-26: desktop (lg+) right panel of the feed: track info and comments of the active post.
import { useState } from 'react';
import { Link } from 'react-router';
import type { Comment, Post } from '@/api/types';
import { Cover } from '@/components/ui/Media';
import { reportTitle } from '@/features/reports/reportOptions';
import { CommentsBody } from './PostSheets';

export function PostSidePanel({ post }: { post: Post }) {
  const [reporting, setReporting] = useState<Comment | null>(null);
  const { track } = post;
  return (
    <aside
      aria-label="Détails de l’extrait"
      className="bg-bg text-fg border-line flex h-full w-96 shrink-0 flex-col border-l"
      // Typing here must not trigger the feed's j/k/arrow shortcuts.
      onKeyDown={(e) => {
        if (e.key !== 'Escape' && e.key !== 'Tab') e.stopPropagation();
      }}
    >
      <div className="border-line flex items-center gap-3 border-b p-4">
        <Cover
          url={track.coverUrl}
          seed={track.id}
          className="size-16 shrink-0"
          rounded="rounded-xl"
        />
        <div className="min-w-0">
          <Link
            to={`/t/${encodeURIComponent(track.id)}`}
            className="block truncate font-semibold hover:underline"
          >
            {track.title}
          </Link>
          <p className="text-muted truncate text-sm">{track.artist.name}</p>
        </div>
      </div>
      <h2 className="px-4 pt-4 text-sm font-semibold">
        {reporting ? reportTitle('comment') : 'Commentaires'}
      </h2>
      <div className="min-h-0 flex-1 overflow-y-auto p-4">
        <CommentsBody
          post={post}
          onCountDelta={() => {}}
          reporting={reporting}
          setReporting={setReporting}
        />
      </div>
    </aside>
  );
}
