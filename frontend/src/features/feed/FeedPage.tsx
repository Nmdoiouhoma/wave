import { Link } from 'react-router';
import { Button, ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { CompassIcon } from '@/components/ui/icons';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { errorMessage } from '@/lib/errorMessages';
import type { FeedSource } from './feedApi';
import { FeedViewer } from './FeedViewer';
import { useFeedSession } from './useFeedSession';

const TABS = [
  { to: '/', label: 'Pour toi', id: 'for-you' },
  { to: '/following', label: 'Abonnements', id: 'following' },
] as const;

function FeedTabs({ which }: { which: FeedSource }) {
  return (
    <nav
      aria-label="Fils"
      className="pointer-events-none absolute inset-x-0 top-0 z-20 flex justify-center bg-gradient-to-b from-black/60 to-transparent pt-[max(0.75rem,env(safe-area-inset-top))] pb-6"
    >
      <div className="pointer-events-auto flex gap-1">
        {TABS.map((t) => (
          <Link
            key={t.id}
            to={t.to}
            aria-current={t.id === which ? 'page' : undefined}
            className={cx(
              'rounded-full px-4 py-2 text-sm font-semibold transition',
              t.id === which ? 'bg-white/20 text-white' : 'text-white/70 hover:text-white',
            )}
          >
            {t.label}
          </Link>
        ))}
      </div>
    </nav>
  );
}

const EMPTY: Record<FeedSource, { title: string; body: string }> = {
  'for-you': {
    title: 'Aucun extrait pour l’instant',
    body: 'Personne n’a encore publié d’extrait. Va voir ce qui se passe dans Découvrir.',
  },
  following: {
    title: 'Rien de neuf dans tes abonnements',
    body: 'Les extraits des gens que tu suis apparaîtront ici. Trouve qui suivre dans Découvrir.',
  },
};

/** W-34: `/` (Pour toi) and `/following` (Abonnements). */
export function FeedPage({ which }: { which: FeedSource }) {
  // Remount per source: each tab is its own session and its own audio.
  return <FeedSessionView key={which} which={which} />;
}

function FeedSessionView({ which }: { which: FeedSource }) {
  const { session, posts, query, loadMore, restart } = useFeedSession(which);
  const header = <FeedTabs which={which} />;

  if (query.isPending || (query.isSuccess && !posts.length && query.hasNextPage))
    return (
      <div className="relative flex h-full items-center justify-center bg-black text-white">
        {header}
        <Spinner />
      </div>
    );
  if (query.isError && !posts.length)
    return (
      <div className="relative flex h-full items-center justify-center bg-black text-white">
        {header}
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      </div>
    );
  if (!posts.length)
    return (
      <div className="relative flex h-full items-center justify-center bg-black px-6 text-white">
        {header}
        <EmptyState icon={<CompassIcon size={28} />} title={EMPTY[which].title}>
          <p>{EMPTY[which].body}</p>
          <ButtonLink to="/discover" size="sm" variant="secondary" className="mt-3">
            Découvrir
          </ButtonLink>
        </EmptyState>
      </div>
    );

  let footer;
  if (query.isFetchNextPageError)
    footer = (
      <div role="alert" className="flex flex-col items-center gap-3">
        <p className="text-sm text-white/80">{errorMessage(query.error)}</p>
        <Button variant="secondary" size="sm" onClick={() => void query.fetchNextPage()}>
          Réessayer
        </Button>
      </div>
    );
  else if (query.hasNextPage) footer = <Spinner />;
  else
    footer = (
      <div className="flex flex-col items-center gap-3">
        <p className="text-2xl font-bold">Tu as tout vu</p>
        <p className="text-sm text-white/70">Reviens plus tard, ou repars du début.</p>
        <Button size="sm" onClick={restart}>
          Recommencer
        </Button>
      </div>
    );

  return (
    <FeedViewer key={session} posts={posts} header={header} footer={footer} onNearEnd={loadMore} />
  );
}
