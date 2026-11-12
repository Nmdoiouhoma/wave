// W-36: comment helpers shared by the panel and its tests.
export const COMMENT_MAX = 500;

export const commentsKey = (postId: string) => ['comments', postId] as const;

const rtf = new Intl.RelativeTimeFormat('fr', { numeric: 'auto' });
const STEPS: [Intl.RelativeTimeFormatUnit, number][] = [
  ['minute', 60],
  ['hour', 3600],
  ['day', 86_400],
  ['week', 604_800],
  ['month', 2_592_000],
  ['year', 31_536_000],
];

/** "il y a 3 minutes", "hier"… ; under a minute: "à l’instant". */
export function timeAgo(iso: string, now = Date.now()): string {
  const seconds = (Date.parse(iso) - now) / 1000;
  if (Math.abs(seconds) < 60) return 'à l’instant';
  let unit: Intl.RelativeTimeFormatUnit = 'minute';
  let size = 60;
  for (const [u, s] of STEPS) {
    if (Math.abs(seconds) < s) break;
    unit = u;
    size = s;
  }
  return rtf.format(Math.round(seconds / size), unit);
}
