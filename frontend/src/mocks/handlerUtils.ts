// Shared helpers of the MSW handlers (problem+json, auth, pagination).
import { HttpResponse, type JsonBodyType } from 'msw';
import type { ProblemCode, Violation } from '@/api/types';
import type { MockUser } from './fixtures';
import { findUserById, parseAccessToken, tick } from './db';

const TITLES: Partial<Record<ProblemCode, string>> = {
  validation_failed: 'Validation failed',
  not_found: 'Not found',
  unauthenticated: 'Unauthenticated',
  token_expired: 'Token expired',
};

export function problem(
  status: number,
  code: ProblemCode,
  extra: { detail?: string; violations?: Violation[]; headers?: Record<string, string> } = {},
) {
  return HttpResponse.json(
    {
      type: `urn:wave:problem:${code}`,
      title: TITLES[code] ?? code.replace(/_/g, ' '),
      status,
      code,
      ...(extra.detail ? { detail: extra.detail } : {}),
      ...(extra.violations ? { violations: extra.violations } : {}),
    },
    { status, headers: { 'Content-Type': 'application/problem+json', ...extra.headers } },
  );
}

export const violation = (propertyPath: string, message: string): Violation => ({
  propertyPath,
  message,
  code: null,
});

export function invalid(violations: Violation[]) {
  return problem(422, 'validation_failed', { violations });
}

export function json(body: JsonBodyType, status = 200) {
  return HttpResponse.json(body, { status });
}

export const noContent = () => new HttpResponse(null, { status: 204 });
export const accepted = () => new HttpResponse(null, { status: 202 });

/** Resolves the bearer; throws the 401 response otherwise (MSW supports thrown responses). */
function viewerOf(request: Request): MockUser | null {
  const header = request.headers.get('Authorization');
  if (!header?.startsWith('Bearer ')) return null;
  const parsed = parseAccessToken(header.slice(7));
  if (!parsed) throw problem(401, 'unauthenticated');
  if (parsed.exp <= Date.now()) throw problem(401, 'token_expired');
  const user = findUserById(parsed.userId);
  if (!user) throw problem(401, 'unauthenticated');
  return user;
}

export function currentSid(request: Request): string | null {
  const header = request.headers.get('Authorization');
  return header?.startsWith('Bearer ') ? (parseAccessToken(header.slice(7))?.sid ?? null) : null;
}

export function requireUser(request: Request): MockUser {
  tick();
  const user = viewerOf(request);
  if (!user) throw problem(401, 'unauthenticated');
  return user;
}

export function requireVerified(user: MockUser): void {
  if (!user.emailVerified) throw problem(403, 'email_not_verified');
}

export async function readJson<T>(request: Request): Promise<T> {
  try {
    return (await request.json()) as T;
  } catch {
    throw problem(400, 'malformed_request');
  }
}

/** Issues a refresh token (new family on login/register, same family on rotation). */
export function paginate<T>(
  items: T[],
  request: Request,
  maxLimit = 50,
  defaultLimit = 20,
): { items: T[]; nextCursor: string | null } {
  const url = new URL(request.url);
  const limit = Math.min(
    maxLimit,
    Math.max(1, Number(url.searchParams.get('limit') ?? defaultLimit) || defaultLimit),
  );
  const cursor = url.searchParams.get('cursor');
  let offset = 0;
  if (cursor) {
    try {
      const parsed = JSON.parse(atob(cursor)) as { o: number };
      offset = parsed.o;
      if (!Number.isInteger(offset) || offset < 0) throw new Error('bad');
    } catch {
      throw problem(400, 'invalid_cursor');
    }
  }
  const page = items.slice(offset, offset + limit);
  const next = offset + limit < items.length ? btoa(JSON.stringify({ o: offset + limit })) : null;
  return { items: page, nextCursor: next };
}
