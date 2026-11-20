import { useSyncExternalStore } from 'react';

/** `false` where `matchMedia` is missing (tests, old browsers): the mobile layout is the default. */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      if (typeof window.matchMedia !== 'function') return () => {};
      const mql = window.matchMedia(query);
      mql.addEventListener('change', onChange);
      return () => mql.removeEventListener('change', onChange);
    },
    () => typeof window.matchMedia === 'function' && window.matchMedia(query).matches,
  );
}

export const DESKTOP_QUERY = '(min-width: 768px)';
/** FB-26: wide enough for the feed's right panel (track info + comments). */
export const WIDE_QUERY = '(min-width: 1024px)';
/** FB-32: room for the feed's left suggestions column (rail 240 + 232 + feed + 384 panel). */
export const XL_QUERY = '(min-width: 1280px)';
