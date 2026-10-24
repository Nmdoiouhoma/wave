import type { Problem, ProblemCode, Violation } from './types';

/** Client-side codes that never come from the server. */
export type ClientErrorCode = 'network_error' | 'aborted';
export type ErrorCode = ProblemCode | ClientErrorCode;

const STATUS_FALLBACK: Record<number, ProblemCode> = {
  400: 'malformed_request',
  401: 'unauthenticated',
  403: 'forbidden',
  404: 'not_found',
  410: 'stream_token_expired',
  // CONVENTIONS §5: any 413 (including nginx's bare one) is payload_too_large.
  413: 'payload_too_large',
  415: 'unsupported_media_type',
  422: 'validation_failed',
  429: 'rate_limited',
};

export class ApiError extends Error {
  readonly status: number;
  readonly code: ErrorCode;
  readonly detail: string | null;
  readonly violations: Violation[];
  /** Seconds, from `Retry-After` on 429. */
  readonly retryAfter: number | null;

  constructor(init: {
    status: number;
    code: ErrorCode;
    title?: string;
    detail?: string | null;
    violations?: Violation[];
    retryAfter?: number | null;
  }) {
    super(init.title ?? init.code);
    this.name = 'ApiError';
    this.status = init.status;
    this.code = init.code;
    this.detail = init.detail ?? null;
    this.violations = init.violations ?? [];
    this.retryAfter = init.retryAfter ?? null;
  }

  is(...codes: ErrorCode[]): boolean {
    return codes.includes(this.code);
  }
}

export function isApiError(e: unknown): e is ApiError {
  return e instanceof ApiError;
}

export function codeForStatus(status: number): ProblemCode {
  return STATUS_FALLBACK[status] ?? 'internal_error';
}

function isProblem(v: unknown): v is Problem {
  return (
    typeof v === 'object' &&
    v !== null &&
    typeof (v as Problem).code === 'string' &&
    typeof (v as Problem).status === 'number'
  );
}

export function parseRetryAfter(value: string | null): number | null {
  if (!value) return null;
  const n = Number(value);
  if (Number.isFinite(n) && n >= 0) return Math.ceil(n);
  const date = Date.parse(value);
  return Number.isNaN(date) ? null : Math.max(0, Math.ceil((date - Date.now()) / 1000));
}

/** Builds a typed error from a raw status/body/header triple (shared by fetch and XHR paths). */
export function problemToError(status: number, body: unknown, retryAfter: string | null): ApiError {
  if (isProblem(body)) {
    return new ApiError({
      status,
      code: body.code,
      title: body.title,
      detail: body.detail ?? null,
      violations: body.violations ?? [],
      retryAfter: parseRetryAfter(retryAfter),
    });
  }
  return new ApiError({
    status,
    code: codeForStatus(status),
    retryAfter: parseRetryAfter(retryAfter),
  });
}

export async function responseToError(res: Response): Promise<ApiError> {
  let body: unknown;
  try {
    const text = await res.text();
    body = text ? JSON.parse(text) : null;
  } catch {
    body = null;
  }
  return problemToError(res.status, body, res.headers.get('Retry-After'));
}

/** `violations` → `{ propertyPath: message }` (first message per field). */
export function fieldErrors(error: unknown): Record<string, string> {
  if (!isApiError(error)) return {};
  const out: Record<string, string> = {};
  for (const v of error.violations) {
    if (!(v.propertyPath in out)) out[v.propertyPath] = v.message;
  }
  return out;
}
