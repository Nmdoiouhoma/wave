/** W-43b: type tabs of the search page (`all` = the `/search` overview). */
export const SEARCH_TABS = [
  { id: 'all', label: 'Tout' },
  { id: 'users', label: 'Personnes' },
  { id: 'tracks', label: 'Morceaux' },
  { id: 'artists', label: 'Artistes' },
  { id: 'playlists', label: 'Playlists' },
  { id: 'hashtags', label: 'Hashtags' },
] as const;
export type SearchTab = (typeof SEARCH_TABS)[number]['id'];
export const isSearchTab = (v: string | null): v is SearchTab =>
  SEARCH_TABS.some((t) => t.id === v);
