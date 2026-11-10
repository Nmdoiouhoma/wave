/** Only same-app relative paths are allowed as post-login redirects. */
export function safeNext(next: string | null): string {
  if (!next || !next.startsWith('/') || next.startsWith('//') || next.startsWith('/\\')) return '/';
  // The URL parser drops tabs and newlines ("/\t/evil.com" → "//evil.com"): the resolved
  // origin is the only reliable check.
  try {
    const url = new URL(next, window.location.origin);
    if (url.origin !== window.location.origin) return '/';
    return url.pathname + url.search + url.hash;
  } catch {
    return '/';
  }
}
