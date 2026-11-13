import { useSyncExternalStore } from 'react';

/** FB-27: user choice. 'system' follows prefers-color-scheme (no data-theme attribute). */
export type ThemeChoice = 'light' | 'dark' | 'system';

const KEY = 'wave-theme';
const listeners = new Set<() => void>();

export function readTheme(): ThemeChoice {
  try {
    const v = localStorage.getItem(KEY);
    if (v === 'light' || v === 'dark') return v;
  } catch {
    // Storage blocked: system theme.
  }
  return 'system';
}

let current: ThemeChoice = readTheme();

function apply(choice: ThemeChoice) {
  const root = document.documentElement;
  if (choice === 'system') delete root.dataset.theme;
  else root.dataset.theme = choice;
}

export function setTheme(choice: ThemeChoice) {
  current = choice;
  try {
    if (choice === 'system') localStorage.removeItem(KEY);
    else localStorage.setItem(KEY, choice);
  } catch {
    // Not persisted, still applied for this session.
  }
  apply(choice);
  listeners.forEach((l) => l());
}

export function useTheme(): [ThemeChoice, (c: ThemeChoice) => void] {
  const choice = useSyncExternalStore(
    (cb) => {
      listeners.add(cb);
      return () => listeners.delete(cb);
    },
    () => current,
  );
  return [choice, setTheme];
}
