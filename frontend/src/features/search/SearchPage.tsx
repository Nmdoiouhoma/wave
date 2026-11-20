import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router';
import { useCurrentUser } from '@/api/queries/me';
import { SearchIcon } from '@/components/ui/icons';
import { EmptyState } from '@/components/ui/States';
import { CatalogSearchBlock } from '@/features/catalog/CatalogSections';
import { DiscoverHomeView } from '@/features/discover/DiscoverHomeView';
import { SearchResults } from './SearchResults';
import { isSearchTab, type SearchTab } from './searchTabs';
import { useDebounced } from '@/lib/useDebounced';

export const SEARCH_DEBOUNCE_MS = 300;
export const SEARCH_MIN_CHARS = 2;

/** W-28/W-43b, mounted on `/discover`: type-tab search (all, people, tracks, artists, playlists, hashtags). `?q=` keeps the query in the URL (back button). */
export function SearchPage() {
  const catalog = useCurrentUser().features.catalog;
  const [params, setParams] = useSearchParams();
  // The field owns the text while typing; the URL only follows (debounced) and never rewrites it.
  const [input, setInput] = useState(() => params.get('q') ?? '');
  const debouncedInput = useDebounced(input, SEARCH_DEBOUNCE_MS);
  const q = debouncedInput.trim();
  useEffect(() => {
    setParams(
      (prev) => {
        if (!debouncedInput) return {};
        const next: Record<string, string> = { q: debouncedInput };
        const t = prev.get('tab');
        if (t) next.tab = t;
        return next;
      },
      { replace: true },
    );
  }, [debouncedInput, setParams]);
  // W-43b: type tab in the URL (`?tab=`); a `#tag` query opens Hashtags unless a tab is set.
  const rawTab = params.get('tab');
  const tab: SearchTab = isSearchTab(rawTab) ? rawTab : q.startsWith('#') ? 'hashtags' : 'all';
  const changeTab = (t: SearchTab) =>
    setParams(
      (prev) => {
        const next = new URLSearchParams(prev);
        next.set('tab', t);
        return next;
      },
      { replace: true },
    );
  const tooShort = q.length < SEARCH_MIN_CHARS;

  return (
    <div className={tooShort ? 'mx-auto max-w-5xl' : 'mx-auto max-w-xl'}>
      <h1 className="text-2xl font-bold tracking-tight">Découvrir</h1>
      <form role="search" onSubmit={(e) => e.preventDefault()} className="relative mt-4">
        <label htmlFor="search-input" className="sr-only">
          Rechercher sur Wave
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
          placeholder="Personne, morceau, artiste, #hashtag"
          value={input}
          onChange={(e) => setInput(e.target.value)}
          aria-describedby="search-hint"
          className="bg-surface-2 ring-line placeholder:text-faint focus:ring-accent h-12 w-full rounded-full pr-4 pl-12 ring-1 focus:ring-2 focus:outline-none"
        />
      </form>
      <p id="search-hint" className="text-faint mt-2 px-1 text-xs">
        Personnes, morceaux, artistes, playlists et hashtags.
      </p>

      {tooShort ? (
        q.length === 0 ? (
          <DiscoverHomeView />
        ) : (
          <div className="mt-4">
            <EmptyState icon={<SearchIcon size={28} />} title="Trouve des gens à suivre">
              Tape au moins {SEARCH_MIN_CHARS} caractères.
            </EmptyState>
          </div>
        )
      ) : (
        <SearchResults q={q} tab={tab} onTab={changeTab} />
      )}
      {catalog && q.length >= SEARCH_MIN_CHARS && <CatalogSearchBlock q={q} />}
    </div>
  );
}
