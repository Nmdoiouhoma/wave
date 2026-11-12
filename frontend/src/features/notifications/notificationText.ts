// W-46: French wording, links and dates of a notification. Pure, tested as a table.
import type { Notification, NotificationPage } from './notificationsApi';

export interface Sentence {
  /** Names shown in bold, in order ("Alice", "Bob"). */
  names: string[];
  /** Plain-text rest of the sentence ("et 2 autres ont aimé ton morceau « X »"). */
  rest: string;
}

function verb(n: Notification, plural: boolean): string {
  const quoted = n.target ? ` « ${n.target.label} »` : '';
  const has = plural ? 'ont' : 'a';
  switch (n.type) {
    case 'follow':
      return `${has} commencé à te suivre`;
    case 'track_like':
      return `${has} aimé ton morceau${quoted}`;
    case 'playlist_like':
      return `${has} aimé ta playlist${quoted}`;
    case 'post_like':
      return `${has} aimé ton post${quoted}`;
    case 'comment':
      return `${has} commenté ton post${quoted}`;
  }
}

/** "Alice a…", "Alice et Bob ont…", "Alice et 2 autres ont…". Labels stay plain text. */
export function sentence(n: Notification): Sentence {
  const first = n.actors[0]?.displayName ?? 'Quelqu’un';
  const count = Math.max(n.actorCount, n.actors.length, 1);
  if (count === 1) return { names: [first], rest: verb(n, false) };
  const second = n.actors[1];
  if (count === 2 && second) return { names: [first, second.displayName], rest: verb(n, true) };
  const others = count - 1;
  return {
    names: [first],
    rest: `et ${others} ${others > 1 ? 'autres' : 'autre'} ${verb(n, true)}`,
  };
}

export function sentenceText(n: Notification): string {
  const s = sentence(n);
  return s.names.length === 2
    ? `${s.names[0]} et ${s.names[1]} ${s.rest}`
    : `${s.names[0]} ${s.rest}`;
}

/** Where a tap leads: the actor's profile for `follow`, else the target. */
export function notificationHref(n: Notification): string {
  const enc = encodeURIComponent;
  if (n.type === 'follow' || !n.target) {
    const actor = n.actors[0];
    return actor ? `/u/${enc(actor.username)}` : '/notifications';
  }
  switch (n.target.type) {
    case 'track':
      return `/t/${enc(n.target.id)}`;
    case 'playlist':
      return `/p/${enc(n.target.id)}`;
    case 'post':
      return `/posts/${enc(n.target.id)}`;
  }
}

/**
 * `upTo` of `POST /me/notifications/read`: the `updatedAt` string of the newest displayed item,
 * returned as is. Never `new Date(...).toISOString()`: that truncates the microseconds and the
 * newest item would stay unread (exact comparison server side).
 */
export function readUpTo(pages: NotificationPage[] | undefined): string | null {
  return pages?.[0]?.items[0]?.updatedAt ?? null;
}

/** Display only: JS dates keep milliseconds, so extra fraction digits are dropped before parsing. */
export function parseDate(iso: string): number {
  return Date.parse(iso.replace(/(\.\d{3})\d+/, '$1'));
}

const dateFmt = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' });
const dateYearFmt = new Intl.DateTimeFormat('fr-FR', {
  day: 'numeric',
  month: 'short',
  year: 'numeric',
});

/** "à l’instant", "il y a 5 min", "il y a 3 h", "il y a 2 j", then the date. */
export function relativeTime(iso: string, now: number = Date.now()): string {
  const t = parseDate(iso);
  if (!Number.isFinite(t)) return '';
  const s = Math.max(0, Math.floor((now - t) / 1000));
  if (s < 60) return 'à l’instant';
  const m = Math.floor(s / 60);
  if (m < 60) return `il y a ${m} min`;
  const h = Math.floor(m / 60);
  if (h < 24) return `il y a ${h} h`;
  const d = Math.floor(h / 24);
  if (d < 7) return `il y a ${d} j`;
  return new Date(t).getFullYear() === new Date(now).getFullYear()
    ? dateFmt.format(t)
    : dateYearFmt.format(t);
}

/** Badge text: nothing at 0, "99+" from 100 (the server caps at 100). */
export function badgeText(count: number): string | null {
  if (count <= 0) return null;
  return count >= 100 ? '99+' : String(count);
}

/** Pages flattened, first occurrence kept (an item can move to the top between two pages). */
export function dedupe(pages: NotificationPage[] | undefined): Notification[] {
  const seen = new Set<string>();
  const out: Notification[] = [];
  for (const p of pages ?? [])
    for (const n of p.items) {
      if (seen.has(n.id)) continue;
      seen.add(n.id);
      out.push(n);
    }
  return out;
}
