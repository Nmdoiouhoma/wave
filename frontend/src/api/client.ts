// Framework-agnostic HTTP client: in-memory bearer, single-flight refresh, typed problem+json errors.
// No React import here so the React Native app can reuse it with another SessionPort.
import { ApiError, problemToError, responseToError } from './errors';
import type { AuthSession } from './types';

export interface SessionPort {
  getAccessToken(): string | null;
  setSession(session: AuthSession): void;
  clearSession(): void;
}

export type QueryValue = string | number | boolean | null | undefined;

export interface RequestOptions {
  query?: Record<string, QueryValue>;
  /** JSON body. */
  body?: unknown;
  /** Multipart body (no upload progress; use `upload` for that). */
  form?: FormData;
  /** Send the bearer token and refresh on `token_expired` (default true). */
  auth?: boolean;
  signal?: AbortSignal;
  keepalive?: boolean;
}

export interface UploadOptions {
  query?: Record<string, QueryValue>;
  onProgress?: (loaded: number, total: number) => void;
  signal?: AbortSignal;
}

export interface ApiClientOptions {
  /** e.g. `/api/v1` or `https://wave.example/api/v1`. */
  baseUrl: string;
  session: SessionPort;
  fetchImpl?: typeof fetch;
}

export interface ApiClient {
  request<T>(method: string, path: string, options?: RequestOptions): Promise<T>;
  upload<T>(method: string, path: string, form: FormData, options?: UploadOptions): Promise<T>;
  /** Single-flight refresh; resolves `true` when a new session was opened. */
  refresh(): Promise<boolean>;
  url(path: string, query?: Record<string, QueryValue>): string;
}

function resolveBase(baseUrl: string): string {
  const trimmed = baseUrl.replace(/\/+$/, '');
  if (/^https?:\/\//.test(trimmed)) return trimmed;
  const origin =
    typeof window !== 'undefined' && window.location ? window.location.origin : 'http://localhost';
  return origin + (trimmed.startsWith('/') ? trimmed : `/${trimmed}`);
}

function parseJson(text: string): unknown {
  if (!text) return undefined;
  try {
    return JSON.parse(text);
  } catch {
    return undefined;
  }
}

export function createApiClient({ baseUrl, session, fetchImpl }: ApiClientOptions): ApiClient {
  const doFetch: typeof fetch = (...args) => (fetchImpl ?? fetch)(...args);
  let refreshing: Promise<boolean> | null = null;

  const url = (path: string, query?: Record<string, QueryValue>): string => {
    const u = new URL(resolveBase(baseUrl) + path);
    if (query) {
      for (const [k, v] of Object.entries(query)) {
        if (v !== undefined && v !== null && v !== '') u.searchParams.set(k, String(v));
      }
    }
    return u.toString();
  };

  // Web: the refresh token lives in the httpOnly `wave_refresh` cookie (Path=/api/v1/auth).
  const isAuthPath = (path: string) => path.startsWith('/auth/');

  async function doRefresh(): Promise<boolean> {
    let res: Response;
    try {
      res = await doFetch(url('/auth/refresh'), {
        method: 'POST',
        credentials: 'include',
        headers: { Accept: 'application/json' },
      });
    } catch {
      throw new ApiError({ status: 0, code: 'network_error' });
    }
    if (res.ok) {
      session.setSession((await res.json()) as AuthSession);
      return true;
    }
    if (res.status === 401 || res.status === 403) {
      session.clearSession();
      return false;
    }
    // 429/5xx: keep the current state, surface the error.
    throw await responseToError(res);
  }

  function refresh(): Promise<boolean> {
    refreshing ??= doRefresh().finally(() => {
      refreshing = null;
    });
    return refreshing;
  }

  /** After a 401 `token_expired` sent with `usedToken`: true if the request may be replayed. */
  async function recover(usedToken: string | null): Promise<boolean> {
    const current = session.getAccessToken();
    // Someone already refreshed while this request was in flight.
    if (current && current !== usedToken) return true;
    return refresh();
  }

  async function request<T>(
    method: string,
    path: string,
    options: RequestOptions = {},
  ): Promise<T> {
    const { query, body, form, auth = true, signal, keepalive } = options;

    const send = async (): Promise<{ res: Response; token: string | null }> => {
      const token = auth ? session.getAccessToken() : null;
      const headers: Record<string, string> = { Accept: 'application/json' };
      if (token) headers.Authorization = `Bearer ${token}`;
      let payload: BodyInit | undefined;
      if (form) payload = form;
      else if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
      }
      try {
        const res = await doFetch(url(path, query), {
          method,
          headers,
          body: payload,
          signal,
          keepalive,
          credentials: isAuthPath(path) ? 'include' : 'same-origin',
        });
        return { res, token };
      } catch (e) {
        if (signal?.aborted) throw new ApiError({ status: 0, code: 'aborted' });
        throw new ApiError({ status: 0, code: 'network_error', detail: String(e) });
      }
    };

    const first = await send();
    let res = first.res;
    if (res.status === 401 && auth) {
      const err = await responseToError(res);
      if (err.code !== 'token_expired') throw err;
      if (!(await recover(first.token))) throw err;
      ({ res } = await send());
    }
    if (!res.ok) throw await responseToError(res);
    return parseJson(await res.text()) as T;
  }

  function xhrOnce<T>(
    method: string,
    path: string,
    form: FormData,
    options: UploadOptions,
  ): Promise<{ ok: true; data: T } | { ok: false; error: ApiError; token: string | null }> {
    return new Promise((resolve, reject) => {
      const token = session.getAccessToken();
      const xhr = new XMLHttpRequest();
      xhr.open(method, url(path, options.query));
      xhr.setRequestHeader('Accept', 'application/json');
      if (token) xhr.setRequestHeader('Authorization', `Bearer ${token}`);
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) options.onProgress?.(e.loaded, e.total);
      };
      xhr.onload = () => {
        const body = parseJson(xhr.responseText);
        if (xhr.status >= 200 && xhr.status < 300) resolve({ ok: true, data: body as T });
        else
          resolve({
            ok: false,
            token,
            error: problemToError(xhr.status, body, xhr.getResponseHeader('Retry-After')),
          });
      };
      xhr.onerror = () => reject(new ApiError({ status: 0, code: 'network_error' }));
      xhr.onabort = () => reject(new ApiError({ status: 0, code: 'aborted' }));
      if (options.signal) {
        if (options.signal.aborted) {
          reject(new ApiError({ status: 0, code: 'aborted' }));
          return;
        }
        options.signal.addEventListener('abort', () => xhr.abort(), { once: true });
      }
      xhr.send(form);
    });
  }

  async function upload<T>(
    method: string,
    path: string,
    form: FormData,
    options: UploadOptions = {},
  ): Promise<T> {
    let result = await xhrOnce<T>(method, path, form, options);
    if (!result.ok && result.error.code === 'token_expired' && (await recover(result.token))) {
      result = await xhrOnce<T>(method, path, form, options);
    }
    if (!result.ok) throw result.error;
    return result.data;
  }

  return { request, upload, refresh, url };
}
