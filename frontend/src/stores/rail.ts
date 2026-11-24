import { useSyncExternalStore } from 'react';

/** Desktop rail folded to icons only, remembered in localStorage (fb-1124). */
const KEY = 'wave-rail-collapsed';
const listeners = new Set<() => void>();

function read(): boolean {
  try {
    return localStorage.getItem(KEY) === '1';
  } catch {
    // Storage blocked: unfolded.
    return false;
  }
}

let current = read();

export function setRailCollapsed(collapsed: boolean) {
  current = collapsed;
  try {
    if (collapsed) localStorage.setItem(KEY, '1');
    else localStorage.removeItem(KEY);
  } catch {
    // Not persisted, still applied for this session.
  }
  listeners.forEach((l) => l());
}

export function useRailCollapsed(): [boolean, (c: boolean) => void] {
  const collapsed = useSyncExternalStore(
    (l) => {
      listeners.add(l);
      return () => listeners.delete(l);
    },
    () => current,
  );
  return [collapsed, setRailCollapsed];
}
