import { Link, useParams } from 'react-router';
import { isApiError } from '@/api/errors';
import { useHashtag, useHashtagPosts } from '@/api/queries/hashtags';
import type { Post } from '@/api/types';
import { ButtonLink } from '@/components/ui/Button';
import { ChevronDownIcon, HeartIcon, MusicIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Cover } from '@/components/ui/Media';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { formatCount } from '@/lib/format';

/** Lowercase, without `#` (contract: HashtagName). */
const normalizeHashtag = (raw: string) => raw.replace(/^#+/, '').toLowerCase();

const postImage = (p: Post) =>
  p.visual ? (p.visual.type === 'video' ? p.visual.posterUrl : p.visual.url) : null;

function PostCard({ post }: { post: Post }) {
  return (
    <li>
      <Link
        to={`/posts/${post.id}`}
        aria-label={`Publication de ${post.author.displayName} : ${post.track.title}`}
        className="hover:bg-surface-2 flex flex-col gap-2 rounded-2xl p-2 transition"
      >
        <Cover
          url={postImage(post) ?? post.track.coverUrl}
          seed={post.id}
          rounded="rounded-xl"
          className="aspect-square w-full"
        />
        <span className="min-w-0 px-1">
          <span className="block truncate font-semibold">{post.track.title}</span>
          {post.caption && (
            <span className="text-muted line-clamp-2 block text-xs">{post.caption}</span>
          )}
          <span className="text-faint mt-0.5 flex items-center gap-2 text-xs">
            <span className="truncate">{post.author.displayName}</span>
            <span className="flex shrink-0 items-center gap-1">
              <HeartIcon size={12} />
              {formatCount(post.likeCount)}
            </span>
          </span>
        </span>
      </Link>
    </li>
  );
}

/** W-43b: `/hashtags/:name` — playable posts carrying the hashtag, newest first. */
export function HashtagPage() {
  const { name: raw = '' } = useParams();
  const name = normalizeHashtag(raw);
  const tag = useHashtag(name);
  const posts = useHashtagPosts(name, tag.isSuccess);
  const items = posts.data?.pages.flatMap((p) => p.items) ?? [];

  const back = (
    <Link
      to="/discover"
      className="text-muted hover:text-fg inline-flex items-center gap-1 text-sm"
    >
      <ChevronDownIcon size={16} className="rotate-90" />
      Découvrir
    </Link>
  );

  let body;
  if (tag.isPending) {
    body = (
      <div className="text-muted flex justify-center py-10">
        <Spinner label="Chargement…" />
      </div>
    );
  } else if (tag.isError) {
    body =
      isApiError(tag.error) && tag.error.status === 404 ? (
        <EmptyState icon={<MusicIcon size={28} />} title={`#${name} n’existe pas (ou plus)`}>
          <ButtonLink to="/discover" size="sm" variant="secondary" className="mt-1">
            Retour à Découvrir
          </ButtonLink>
        </EmptyState>
      ) : (
        <ErrorState error={tag.error} onRetry={() => void tag.refetch()} />
      );
  } else if (posts.isPending) {
    body = (
      <div className="text-muted flex justify-center py-10">
        <Spinner label="Chargement des publications…" />
      </div>
    );
  } else if (posts.isError) {
    body =
      isApiError(posts.error) && posts.error.status === 404 ? (
        <EmptyState icon={<MusicIcon size={28} />} title={`#${name} n’existe pas (ou plus)`} />
      ) : (
        <ErrorState error={posts.error} onRetry={() => void posts.refetch()} />
      );
  } else if (!items.length) {
    body = (
      <EmptyState icon={<MusicIcon size={28} />} title={`Aucune publication avec #${name}`}>
        Reviens plus tard, ou publie la première.
      </EmptyState>
    );
  } else {
    body = (
      <>
        <ul
          aria-label={`Publications avec #${name}`}
          className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
        >
          {items.map((p) => (
            <PostCard key={p.id} post={p} />
          ))}
        </ul>
        <InfiniteSentinel
          hasMore={!!posts.hasNextPage}
          loading={posts.isFetchingNextPage}
          onLoadMore={() => void posts.fetchNextPage()}
        />
      </>
    );
  }

  return (
    <div className="mx-auto max-w-5xl">
      {back}
      <h1 className="mt-2 text-2xl font-bold tracking-tight">#{name}</h1>
      {tag.isSuccess && (
        <p className="text-muted mt-1 text-sm">
          {formatCount(tag.data.postCount)} publication{tag.data.postCount > 1 ? 's' : ''}
        </p>
      )}
      <div className="mt-4" aria-live="polite">
        {body}
      </div>
    </div>
  );
}
