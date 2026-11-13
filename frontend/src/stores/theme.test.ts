import { afterEach, describe, expect, it } from 'vitest';
import { readTheme, setTheme } from './theme';

afterEach(() => {
  setTheme('system');
});

describe('theme', () => {
  it('persists the choice and sets data-theme', () => {
    setTheme('dark');
    expect(document.documentElement.dataset.theme).toBe('dark');
    expect(readTheme()).toBe('dark');
  });
  it('system removes the attribute and the stored value', () => {
    setTheme('light');
    setTheme('system');
    expect(document.documentElement.dataset.theme).toBeUndefined();
    expect(readTheme()).toBe('system');
  });
});
