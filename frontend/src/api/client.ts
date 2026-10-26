// Framework-agnostic HTTP client: in-memory bearer, refresh single-flight within and across tabs
// (ADR 008), typed problem+json errors.
// No React import here so the React Native app can reuse it with another SessionPort / TabSync.
import { ApiError, problemToError, responseToError } from './errors';
import { createTabSync, type SessionEndReason, type TabSync } from './tabSync';
import type { AuthSession } from './types';

export interface SessionPort {
  getAccessToken(): string | null;
  /** Current session to hand over to another tab (null when logged out). */
  getSession(): AuthSession | null;
  setSession(session: AuthSession): void;
  clearSession(reason: SessionEndReason): void;
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
  /** Cross-tab coordination; defaults to an in-tab only lock (tests, React Native). */
  tabSync?: TabSync;
}

export interface ApiClient {
  request<T>(method: string, path: string, options?: RequestOptions): Promise<T>;
  upload<T>(method: string, path: string, form: FormData, options?: UploadOptions): Promise<T>;
  /**
   * ADR 009: browser → object storage form POST (presigned policy). No bearer, no cookies;
   * `fields` first, file last. Resolves on 2xx (storage answers 204).
   */
  postToStorage(
    url: string,
    fields: Record<string, string>,
    file: Blob,
    options?: Omit<UploadOptions, 'query'>,
  ): Promise<void>;
  /** Single-flight refresh (across tabs); resolves `true` when a session is available. */
  refresh(): Promise<boolean>;
  /** Boot: take the session of another open tab if any, else refresh from the cookie. */
  restoreSession(): Promise<boolean>;
  /** Stores a session obtained by login/register/password change and shares it with other tabs. */
  adoptSession(session: AuthSession): void;
  /** Clears the session here and in every other tab. */
  endSession(reason: SessionEndReason): void;
  dispose(): void;
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

export function createApiClient({
  baseUrl,
  session,
  fetchImpl,
  tabSync = createTabSync(),
}: ApiClientOptions): ApiClient {
  const doFetch: typeof fetch = (...args) => (fetchImpl ?? fetch)(...args);
  let refreshing: Promise<boolean> | null = null;

  const adoptSession = (s: AuthSession) => {
    session.setSession(s);
    tabSync.publish({ type: 'session', session: s });
  };
  const endSession = (reason: SessionEndReason) => {
    session.clearSession(reason);
    tabSync.publish({ type: 'logout', reason });
  };

  // Other tabs: adopt their fresh tokens and logouts, hand ours over on request.
  const unsubscribe = tabSync.subscribe((m) => {
    if (m.type === 'session') session.setSession(m.session);
    else if (m.type === 'logout') session.clearSession(m.reason);
    else if (m.type === 'session-request') {
      const current = session.getSession();
      if (current) tabSync.publish({ type: 'session-reply', id: m.id, session: current });
    }
  });

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
      adoptSession((await res.json()) as AuthSession);
      return true;
    }
    if (res.status === 401 || res.status === 403) {
      const err = await responseToError(res);
      // token_reused: the server revoked the whole family (probable theft) — every tab is out.
      endSession(err.code === 'token_reused' ? 'token_reused' : 'expired');
      return false;
    }
    // 429/5xx: keep the current state, surface the error.
    throw await responseToError(res);
  }

  function refresh(): Promise<boolean> {
    if (refreshing) return refreshing;
    const tokenAtStart = session.getAccessToken();
    refreshing = tabSync
      .withRefreshLock(async () => {
        // Another tab refreshed while we waited for the lock and shared its token: the cookie
        // we hold is already rotated, refreshing again would only burn a rotation.
        const current = session.getAccessToken();
        if (current && current !== tokenAtStart) return true;
        return doRefresh();
      })
      .finally(() => {
        refreshing = null;
      });
    return refreshing;
  }

  async function restoreSession(): Promise<boolean> {
    const peer = await tabSync.askPeersForSession();
    if (peer && !session.getAccessToken()) {
      session.setSession(peer);
      return true;
    }
    if (session.getAccessToken()) return true;
    return refresh();
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

  function postToStorage(
    target: string,
    fields: Record<string, string>,
    file: Blob,
    options: Omit<UploadOptions, 'query'> = {},
  ): Promise<void> {
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) form.append(k, v);
    form.append('file', file);
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', target);
      xhr.withCredentials = false;
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) options.onProgress?.(e.loaded, e.total);
      };
      xhr.onload = () => {
        if (xhr.status >= 200 && xhr.status < 300) resolve();
        // S3 answers XML errors (EntityTooLarge, AccessDenied: policy expired…), not problem+json.
        else
          reject(
            new ApiError({
              status: xhr.status,
              code: xhr.status === 413 ? 'payload_too_large' : 'storage_rejected',
              detail: xhr.responseText.slice(0, 200),
            }),
          );
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

  return {
    request,
    upload,
    postToStorage,
    refresh,
    url,
    restoreSession,
    adoptSession,
    endSession,
    dispose: () => {
      unsubscribe();
      tabSync.close();
    },
  };
}
