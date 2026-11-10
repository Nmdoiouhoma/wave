import { useSearchParams } from 'react-router';
import { isApiError } from '@/api/errors';
import { useUserSearch } from '@/api/queries/social';
import { Alert } from '@/components/ui/Alert';
import { SearchIcon, UserIcon } from '@/components/ui/icons';
import { InfiniteSentinel } from '@/components/ui/InfiniteSentinel';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState, ErrorState } from '@/components/ui/States';
import { UserList } from '@/features/social/UserList';
import { retryHint } from '@/lib/errorMessages';
import { useDebounced } from '@/lib/useDebounced';

export const SEARCH_DEBOUNCE_MS = 300;
export const SEARCH_MIN_CHARS = 2;

/** W-28, mounted on `/discover` since W-38: people search (phase 2 = users only). `?q=` keeps the query in the URL (back button). */
export function SearchPage() {
  const [params, setParams] = useSearchParams();
  const input = params.get('q') ?? '';
  const q = useDebounced(input.trim(), SEARCH_DEBOUNCE_MS);
  const search = useUserSearch(q);
  const users = search.data?.pages.flatMap((p) => p.items) ?? [];
  const tooShort = q.length < SEARCH_MIN_CHARS;

  return (
    <div className="mx-auto max-w-xl">
      <h1 className="text-2xl font-bold tracking-tight">Découvrir</h1>
      <form role="search" onSubmit={(e) => e.preventDefault()} className="relative mt-4">
        <label htmlFor="search-input" className="sr-only">
          Rechercher une personne
        </label>
        <SearchIcon
          size={20}
          className="text-muted pointer-events-none absolute top-1/2 left-4 -translate-y-1/2"
        />
        <input
          id="search-input"
          type="search"
          autoFocus
          autoComplete="off"
          enterKeyHint="search"
          maxLength={100}
          placeholder="Nom ou @pseudo"
          value={input}
          onChange={(e) =>
            setParams(e.target.value ? { q: e.target.value } : {}, { replace: true })
          }
          aria-describedby="search-hint"
          className="bg-surface-2 ring-line placeholder:text-faint focus:ring-accent h-12 w-full rounded-full pr-4 pl-12 ring-1 focus:ring-2 focus:outline-none"
        />
      </form>
      <p id="search-hint" className="text-faint mt-2 px-1 text-xs">
        Personnes uniquement pour l’instant · les morceaux et artistes arrivent bientôt.
      </p>

      <div className="mt-4" aria-live="polite" aria-busy={search.isFetching}>
        {tooShort ? (
          <EmptyState icon={<SearchIcon size={28} />} title="Trouve des gens à suivre">
            Tape au moins {SEARCH_MIN_CHARS} caractères.
          </EmptyState>
        ) : search.isPending ? (
          <div className="text-muted flex justify-center py-8">
            <Spinner label="Recherche en cours…" />
          </div>
        ) : search.isError ? (
          isApiError(search.error) && search.error.code === 'rate_limited' ? (
            <Alert tone="warning">
              Tu as fait beaucoup de recherches d’un coup. {retryHint(search.error.retryAfter)}
            </Alert>
          ) : (
            <ErrorState error={search.error} onRetry={() => void search.refetch()} />
          )
        ) : users.length ? (
          <>
            <p className="sr-only">{users.length} résultats affichés</p>
            <UserList users={users} label={`Résultats pour « ${q} »`} />
            <InfiniteSentinel
              hasMore={!!search.hasNextPage}
              loading={search.isFetchingNextPage}
              onLoadMore={() => void search.fetchNextPage()}
            />
          </>
        ) : (
          <EmptyState icon={<UserIcon size={28} />} title={`Personne ne correspond à « ${q} »`}>
            Vérifie l’orthographe ou essaie un autre nom.
          </EmptyState>
        )}
      </div>
    </div>
  );
}
