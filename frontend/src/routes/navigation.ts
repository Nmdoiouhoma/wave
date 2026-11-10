// W-38 (FB-11): route map of the navigation shell. Pure helpers, tested as a table.

export type TabId = 'for-you' | 'discover' | 'create' | 'library' | 'profile';

function firstSegments(pathname: string): string[] {
  return pathname
    .split('/')
    .filter(Boolean)
    .map((s) => {
      try {
        return decodeURIComponent(s);
      } catch {
        return s;
      }
    });
}

/** Tab highlighted for a path; `null` when no tab owns the screen (single post, 404). */
export function activeTab(pathname: string, meUsername: string): TabId | null {
  const [first = '', second] = firstSegments(pathname);
  switch (first) {
    case '':
    case 'following':
      return 'for-you';
    case 'posts':
      return second === 'new' ? 'create' : null;
    case 'upload':
      return 'create';
    case 'discover':
    case 'search':
    case 'a':
    case 't':
      return 'discover';
    case 'library':
    case 'p':
      return 'library';
    case 'settings':
      return 'profile';
    case 'me':
      return second === 'artists' ? 'profile' : null;
    case 'u':
      if (!second) return null;
      return second.toLowerCase() === meUsername.toLowerCase() ? 'profile' : 'discover';
    default:
      return null;
  }
}

/** Screens where the feed owns audio: the mini player is hidden and the page fills the viewport. */
export function isFeedPath(pathname: string): boolean {
  const [first = '', second, third] = firstSegments(pathname);
  if (first === '' || (first === 'following' && !second)) return true;
  return first === 'posts' && !!second && second !== 'new' && !third;
}
