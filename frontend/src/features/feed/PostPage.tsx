import { useQuery } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router';
import { isApiError } from '@/api/errors';
import { ButtonLink } from '@/components/ui/Button';
import { ChevronDownIcon, MusicIcon } from '@/components/ui/icons';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { feedApi } from './feedApi';
import { FeedViewer } from './FeedViewer';

function BackButton() {
  const navigate = useNavigate();
  const canGoBack = ((window.history.state as { idx?: number } | null)?.idx ?? 0) > 0;
  return (
    <button
      type="button"
      aria-label="Retour"
      onClick={() => (canGoBack ? navigate(-1) : navigate('/'))}
      className="absolute top-[max(0.75rem,env(safe-area-inset-top))] left-3 z-20 flex size-10 items-center justify-center rounded-full bg-black/40 text-white"
    >
      <ChevronDownIcon size={22} className="rotate-90" />
    </button>
  );
}

/** W-34: `/posts/:id`, one post in the feed viewer. */
export function PostPage() {
  const { id = '' } = useParams();
  const query = useQuery({
    queryKey: ['post', id],
    queryFn: () => feedApi.post(id),
    // The embedded clip URL lives 5 min; FeedPlayback renews it when needed.
    staleTime: Infinity,
    gcTime: 0,
    refetchOnWindowFocus: false,
  });

  if (query.isPending)
    return (
      <div className="flex h-full items-center justify-center bg-bg text-fg">
        <Spinner />
      </div>
    );
  if (query.isError) {
    if (isApiError(query.error) && query.error.status === 404)
      return (
        <div className="flex h-full items-center justify-center px-6">
          <EmptyState icon={<MusicIcon size={28} />} title="Ce post n’est plus disponible">
            <ButtonLink to="/" size="sm" variant="secondary" className="mt-1">
              Retour au fil
            </ButtonLink>
          </EmptyState>
        </div>
      );
    return (
      <div className="flex h-full items-center justify-center">
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      </div>
    );
  }
  return <FeedViewer key={id} posts={[query.data]} header={<BackButton />} />;
}
