import { Link } from 'react-router';
import { Button, ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { CompassIcon } from '@/components/ui/icons';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { errorMessage } from '@/lib/errorMessages';
import { DESKTOP_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { NotificationBell } from '@/features/notifications/NotificationBell';
import type { FeedSource } from './feedApi';
import { FeedViewer } from './FeedViewer';
import { useFeedSession } from './useFeedSession';

const TABS = [
  { to: '/', label: 'Pour toi', id: 'for-you' },
  { to: '/following', label: 'Abonnements', id: 'following' },
] as const;

function FeedTabs({ which, inline = false }: { which: FeedSource; inline?: boolean }) {
  // W-46: on mobile the bell sits top right of this header (the desktop rail has its own).
  const desktop = useMediaQuery(DESKTOP_QUERY);
  return (
    <nav
      aria-label="Fils"
      className={cx(
        'pointer-events-none z-20 flex justify-center pt-[max(0.75rem,env(safe-area-inset-top))]',
        // FB-29: inline (feed viewer) = own row above the post on md+, never over the card.
        inline
          ? 'absolute inset-x-0 top-0 bg-gradient-to-b from-black/60 to-transparent pb-6 md:static md:shrink-0 md:bg-none md:pt-4 md:pb-0'
          : 'absolute inset-x-0 top-0 bg-gradient-to-b from-black/60 to-transparent pb-6 md:from-transparent',
      )}
    >
      <div className="pointer-events-auto flex gap-1">
        {TABS.map((t) => (
          <Link
            key={t.id}
            to={t.to}
            aria-current={t.id === which ? 'page' : undefined}
            className={cx(
              'rounded-full px-4 py-2 text-sm font-semibold transition',
              t.id === which
                ? 'bg-white/20 text-white md:bg-fg md:text-bg'
                : 'text-white/80 hover:text-white md:text-muted md:hover:text-fg',
            )}
          >
            {t.label}
          </Link>
        ))}
      </div>
      {!desktop && (
        <NotificationBell className="pointer-events-auto absolute top-[max(0.25rem,calc(env(safe-area-inset-top)-0.5rem))] right-2 text-white" />
      )}
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
  const { session, initialIndex, setPosition, posts, query, loadMore, restart } =
    useFeedSession(which);
  const header = <FeedTabs which={which} />;
  const rowHeader = <FeedTabs which={which} inline />;

  if (query.isPending || (query.isSuccess && !posts.length && query.hasNextPage))
    return (
      <div className="relative flex h-full items-center justify-center bg-bg text-fg">
        {header}
        <Spinner />
      </div>
    );
  if (query.isError && !posts.length)
    return (
      <div className="relative flex h-full items-center justify-center bg-bg text-fg">
        {header}
        <ErrorState error={query.error} onRetry={() => void query.refetch()} />
      </div>
    );
  if (!posts.length)
    return (
      <div className="relative flex h-full items-center justify-center bg-bg text-fg px-6">
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
        <p className="text-muted text-sm">{errorMessage(query.error)}</p>
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
        <p className="text-muted text-sm">Reviens plus tard, ou repars du début.</p>
        <Button size="sm" onClick={restart}>
          Recommencer
        </Button>
      </div>
    );

  return (
    <FeedViewer
      key={session}
      posts={posts}
      header={rowHeader}
      footer={footer}
      onNearEnd={loadMore}
      initialIndex={initialIndex}
      onActiveChange={setPosition}
    />
  );
}
