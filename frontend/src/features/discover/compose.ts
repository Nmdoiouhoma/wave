import type { DiscoverForYouTrack, DiscoverHome, Track } from '@/api/types';
import { isPlayable } from '@/stores/player';

/** Below this many cover cards in the rows, accounts and hashtags move up as filler (W-43). */
export const THIN_ROWS_THRESHOLD = 12;
export const ACCOUNTS_RICH = 5;
export const ACCOUNTS_THIN = 6;
export const FAMOUS_THIN = 4;

export interface RowItem {
  track: Track;
  /** « Parce que tu écoutes … », « Pour toi » row only. */
  reason?: string;
}
export type HeroKind = 'for-you' | 'new' | 'trending';

export interface DiscoverModel {
  hero: { track: Track; kind: HeroKind; reason?: string } | null;
  /** Rows in display order; an empty row is not listed. */
  rows: { id: 'for-you' | 'new' | 'trending'; items: RowItem[] }[];
  thin: boolean;
  /** Everything the page can show is empty (no hero, no row, no account, no hashtag). */
  empty: boolean;
}

export const reasonText = (r: DiscoverForYouTrack) => `Parce que tu écoutes ${r.reasonLabel}`;

/**
 * Order: hero, genres, « Pour toi », « Nouveautés », « En tendance », accounts + hashtags.
 * Hero = 1st of Pour toi, else 1st of the new tracks, else of the trending ones; never repeated in a row.
 * An empty row is dropped; thin rows (< THIN_ROWS_THRESHOLD cards) make accounts and hashtags move up.
 */
export function composeDiscover(data: DiscoverHome): DiscoverModel {
  const forYou = data.forYouTracks.filter((r) => isPlayable(r.track));
  const news = data.newTracks.filter(isPlayable);
  const trending = data.trendingTracks.filter(isPlayable);

  let hero: DiscoverModel['hero'] = null;
  if (forYou[0]) hero = { track: forYou[0].track, kind: 'for-you', reason: reasonText(forYou[0]) };
  else if (news[0]) hero = { track: news[0], kind: 'new' };
  else if (trending[0]) hero = { track: trending[0], kind: 'trending' };
  const not = (t: Track) => t.id !== hero?.track.id;

  const rows: DiscoverModel['rows'] = [
    {
      id: 'for-you' as const,
      items: forYou
        .filter((r) => not(r.track))
        .map((r) => ({ track: r.track, reason: reasonText(r) })),
    },
    { id: 'new' as const, items: news.filter(not).map((track) => ({ track })) },
    { id: 'trending' as const, items: trending.filter(not).map((track) => ({ track })) },
  ].filter((r) => r.items.length > 0);

  const cards = rows.reduce((n, r) => n + r.items.length, 0);
  return {
    hero,
    rows,
    thin: cards < THIN_ROWS_THRESHOLD,
    empty:
      !hero && !data.suggestedUsers.length && !data.trendingHashtags.length && !data.genres.length,
  };
}
