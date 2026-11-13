// Dismissed announcements, kept in local storage (may be unavailable: every access is guarded).
const STORAGE_KEY = 'wave.announcements.dismissed';
export const dismissKey = (a: { id: string; updatedAt: string }) => `${a.id}:${a.updatedAt}`;

export function readDismissed(): string[] {
  try {
    const raw: unknown = JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '[]');
    return Array.isArray(raw) ? raw.filter((x): x is string => typeof x === 'string') : [];
  } catch {
    return [];
  }
}

export function writeDismissed(keys: string[]) {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(keys.slice(-50)));
  } catch {
    /* storage unavailable: the banner just comes back next time */
  }
}

