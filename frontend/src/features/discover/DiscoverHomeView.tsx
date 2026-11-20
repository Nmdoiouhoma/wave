import { Link } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { useDiscover } from '@/api/queries/discover';
import type { DiscoverHome, UserListItem } from '@/api/types';
import { ButtonLink } from '@/components/ui/Button';
import { cx } from '@/components/ui/cx';
import { Avatar } from '@/components/ui/Media';
import { ErrorState } from '@/components/ui/States';
import { FamousArtistsSection } from '@/features/catalog/CatalogSections';
import { FollowButton } from '@/features/social/FollowButton';
import { formatCount } from '@/lib/format';
import { DESKTOP_QUERY, useMediaQuery } from '@/lib/useMediaQuery';
import { useFollowState } from '@/api/queries/social';
import {
  ACCOUNTS_RICH,
  ACCOUNTS_THIN,
  FAMOUS_THIN,
  composeDiscover,
  type DiscoverModel,
} from './compose';
import { AccountsCard, HashtagChips, SuggestionCard } from './Suggestions';
import { Hero, TrackRowSection } from './TrackCards';

function AccountCard({ user }: { user: UserListItem }) {
  const server = { following: user.isFollowedByMe, followersCount: user.followersCount };
  const state = useFollowState(user.username, server);
  return (
    <li className="border-line bg-surface flex w-37 shrink-0 flex-col items-center gap-1.5 rounded-[20px] border px-2.5 py-3.5 text-center">
      <Link
        to={`/u/${user.username}`}
        className="flex w-full min-w-0 flex-col items-center gap-1.5"
      >
        <Avatar
          url={user.avatarUrl}
          name={user.displayName}
          seed={user.id}
          className="size-14 text-lg"
        />
        <span className="w-full truncate font-semibold">{user.displayName}</span>
        <span className="text-muted w-full truncate text-xs">
          {formatCount(state.followersCount)} abonné{state.followersCount > 1 ? 's' : ''}
        </span>
      </Link>
      {!user.isMe && (
        <FollowButton username={user.username} displayName={user.displayName} server={server} />
      )}
    </li>
  );
}

function Genres({ genres, highlight }: { genres: DiscoverHome['genres']; highlight: boolean }) {
  if (!genres.length) return null;
  return (
    <section aria-label="Genres" className="mt-7">
      <h2 className="mb-2.5 text-lg font-bold">Genres</h2>
      <ul className="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1 md:mx-0 md:flex-wrap md:px-0">
        {genres.map((g, i) => (
          <li
            key={g.id}
            className={cx(
              'bg-surface inline-flex h-9 items-center rounded-full border px-3.5 font-medium whitespace-nowrap',
              highlight && i < 3 ? 'border-accent text-accent' : 'border-line',
            )}
          >
            {g.name}
          </li>
        ))}
      </ul>
    </section>
  );
}

function Skeleton() {
  const bar = (w: string, h = 12) => (
    <div
      className="bg-surface-2 animate-pulse rounded-lg motion-reduce:animate-none"
      style={{ width: w, height: h }}
    />
  );
  return (
    <div role="status" aria-busy="true" aria-label="Chargement de Découvrir" className="mt-5">
      <div className="border-line bg-surface flex flex-col items-center gap-3 rounded-3xl border p-5">
        <div className="bg-surface-2 aspect-square w-3/4 max-w-60 animate-pulse rounded-3xl motion-reduce:animate-none" />
        {bar('60%', 22)}
        {bar('40%')}
        {bar('100%', 52)}
      </div>
      <div className="mt-6 flex flex-col gap-2.5">
        {bar('34%', 20)}
        <div className="flex gap-4 overflow-hidden">
          {[0, 1, 2].map((i) => (
            <div key={i} className="w-36 shrink-0">
              <div className="bg-surface-2 mb-2.5 aspect-square animate-pulse rounded-2xl motion-reduce:animate-none" />
              {bar('70%')}
              <div className="h-1.5" />
              {bar('45%')}
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

export function DiscoverContent({ data, catalog }: { data: DiscoverHome; catalog: boolean }) {
  const desktop = useMediaQuery(DESKTOP_QUERY);
  const model: DiscoverModel = composeDiscover(data);
  const { hero, rows, thin } = model;

  if (model.empty)
    return (
      <div className="border-line bg-surface mt-5 flex flex-col items-center gap-1.5 rounded-[20px] border px-4 py-9 text-center">
        <p className="text-lg font-bold">Rien à découvrir pour l’instant</p>
        <p className="text-muted">Publie le premier extrait, ou reviens un peu plus tard.</p>
        <ButtonLink to="/posts/new" variant="secondary" className="mt-3.5">
          Créer un post
        </ButtonLink>
      </div>
    );

  const accounts = thin ? ACCOUNTS_THIN : ACCOUNTS_RICH;
  const cardsAccounts =
    desktop || thin ? (
      <AccountsCard users={data.suggestedUsers} limit={accounts} />
    ) : (
      <section aria-label="Comptes à suivre" className="mt-7">
        <h2 className="mb-2.5 text-lg font-bold">Comptes à suivre</h2>
        {data.suggestedUsers.length ? (
          <ul className="-mx-4 flex gap-2.5 overflow-x-auto px-4 pb-1">
            {data.suggestedUsers.map((u) => (
              <AccountCard key={u.id} user={u} />
            ))}
          </ul>
        ) : (
          <p className="text-muted text-sm">Personne à te suggérer pour l’instant.</p>
        )}
      </section>
    );

  // Mobile rich: horizontal account cards stand alone; otherwise accounts + hashtags sit side by side.
  const mobileRich = !desktop && !thin;
  const hashtags = data.trendingHashtags.length ? (
    mobileRich ? (
      <section aria-label="Hashtags en tendance" className="mt-7">
        <h2 className="mb-2.5 text-lg font-bold">Hashtags en tendance</h2>
        <HashtagChips tags={data.trendingHashtags.slice(0, 8)} />
      </section>
    ) : (
      <SuggestionCard title="Hashtags en tendance">
        <HashtagChips tags={data.trendingHashtags} />
      </SuggestionCard>
    )
  ) : null;

  return (
    <>
      {hero && (
        <div className="mt-5">
          <Hero track={hero.track} kind={hero.kind} reason={hero.reason} />
        </div>
      )}
      <Genres genres={data.genres} highlight={data.forYouTracks.length > 0} />
      {rows.map((r) => (
        <TrackRowSection key={r.id} kind={r.id} items={r.items} />
      ))}
      {mobileRich ? (
        <>
          {cardsAccounts}
          {hashtags}
        </>
      ) : (
        <div
          className={cx(
            'mt-7 grid items-start gap-6 md:mt-10',
            thin ? 'md:mt-7 md:grid-cols-[1.2fr_1fr]' : 'md:grid-cols-2',
          )}
        >
          {cardsAccounts}
          {hashtags}
        </div>
      )}
      {catalog && <FamousArtistsSection limit={thin ? FAMOUS_THIN : undefined} />}
    </>
  );
}

/** W-43: Découvrir with an empty search box. One `GET /discover`. */
export function DiscoverHomeView() {
  const catalog = useCurrentUser().features.catalog;
  const q = useDiscover();
  if (q.isPending) return <Skeleton />;
  if (q.isError)
    return (
      <div className="border-line bg-surface mt-5 rounded-[20px] border" role="presentation">
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      </div>
    );
  return <DiscoverContent data={q.data} catalog={catalog} />;
}
