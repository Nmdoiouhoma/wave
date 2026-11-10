import { describe, expect, it } from 'vitest';
import { safeNext } from './safeNext';

describe('safeNext', () => {
  it.each([
    ['/library', '/library'],
    ['/p/1?x=2#y', '/p/1?x=2#y'],
    [null, '/'],
    ['https://evil.com', '/'],
    ['//evil.com', '/'],
    ['/\\evil.com', '/'],
    ['/\t/evil.com', '/'],
    ['/\n/evil.com', '/'],
  ])('%j → %s', (next, expected) => {
    expect(safeNext(next)).toBe(expected);
  });
});
