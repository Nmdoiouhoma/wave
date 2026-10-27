// MSW handlers for every phase-1 operation of docs/openapi.yaml.
// `/stream/{token}` is not mocked: mock stream URLs point at static files in public/mock-audio.
import { delay, http, HttpResponse, type JsonBodyType } from 'msw';
import type {
  AuthSession,
  ListenRequest,
  ListenResult,
  ProblemCode,
  TrackUploadSlot,
  StreamUrl,
  TrackPage,
  Violation,
} from '@/api/types';
import { GENRES, type MockTrack, type MockUser } from './fixtures';
import {
  artistVisibleTo,
  db,
  findArtistBySlug,
  findGenre,
  findTrack,
  findUserById,
  findUserByUsername,
  isoNow,
  issueAccessToken,
  mockConfig,
  parseAccessToken,
  persist,
  pickAudio,
  randomToken,
  sendMail,
  slugify,
  revokeFamilies,
  syncFromStorage,
  tick,
  toArtist,
  toMe,
  toProfile,
  toTrack,
  trackVisibleTo,
  uuid7,
} from './db';

const API = '*/api/v1';

const TITLES: Partial<Record<ProblemCode, string>> = {
  validation_failed: 'Validation failed',
  not_found: 'Not found',
  unauthenticated: 'Unauthenticated',
  token_expired: 'Token expired',
};

function problem(
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

const violation = (propertyPath: string, message: string): Violation => ({
  propertyPath,
  message,
  code: null,
});

function invalid(violations: Violation[]) {
  return problem(422, 'validation_failed', { violations });
}

function json(body: JsonBodyType, status = 200) {
  return HttpResponse.json(body, { status });
}

const noContent = () => new HttpResponse(null, { status: 204 });
const accepted = () => new HttpResponse(null, { status: 202 });

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

function currentSid(request: Request): string | null {
  const header = request.headers.get('Authorization');
  return header?.startsWith('Bearer ') ? (parseAccessToken(header.slice(7))?.sid ?? null) : null;
}

function requireUser(request: Request): MockUser {
  tick();
  const user = viewerOf(request);
  if (!user) throw problem(401, 'unauthenticated');
  return user;
}

function requireVerified(user: MockUser): void {
  if (!user.emailVerified) throw problem(403, 'email_not_verified');
}

async function readJson<T>(request: Request): Promise<T> {
  try {
    return (await request.json()) as T;
  } catch {
    throw problem(400, 'malformed_request');
  }
}

/** Issues a refresh token (new family on login/register, same family on rotation). */
function openSession(user: MockUser, status = 200, familyId: string = uuid7()) {
  const refresh = randomToken();
  db.state.refreshTokens[refresh] = { userId: user.id, familyId, rotated: false };
  db.state.cookie = refresh;
  persist();
  const body: AuthSession = {
    accessToken: issueAccessToken(user.id, familyId),
    tokenType: 'Bearer',
    expiresIn: 900,
    user: toMe(user),
  };
  return json(body, status);
}

function paginate<T>(items: T[], request: Request): { items: T[]; nextCursor: string | null } {
  const url = new URL(request.url);
  const limit = Math.min(50, Math.max(1, Number(url.searchParams.get('limit') ?? 20) || 20));
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

const newestFirst = (a: { createdAt: string }, b: { createdAt: string }) =>
  b.createdAt.localeCompare(a.createdAt);

function issueAccountToken(user: MockUser, purpose: 'verify_email' | 'password_reset'): string {
  for (const [k, tok] of Object.entries(db.state.accountTokens)) {
    if (tok.userId === user.id && tok.purpose === purpose && !tok.used)
      delete db.state.accountTokens[k];
  }
  const raw = randomToken();
  db.state.accountTokens[raw] = {
    userId: user.id,
    purpose,
    expiresAt: Date.now() + (purpose === 'verify_email' ? 86_400_000 : 3_600_000),
    used: false,
  };
  return raw;
}

function consumeAccountToken(raw: unknown, purpose: 'verify_email' | 'password_reset') {
  if (typeof raw !== 'string' || !raw) throw problem(400, 'malformed_request');
  const tok = db.state.accountTokens[raw];
  if (!tok || tok.purpose !== purpose || tok.used || tok.expiresAt < Date.now()) {
    throw problem(400, 'account_token_invalid');
  }
  tok.used = true;
  const user = findUserById(tok.userId);
  if (!user) throw problem(400, 'account_token_invalid');
  return user;
}

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const UPLOAD_TYPES = ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/x-m4a', 'video/mp4'];
const UPLOAD_EXT = ['mp3', 'm4a', 'aac', 'mp4'];
const origin = () => (typeof window !== 'undefined' ? window.location.origin : 'http://localhost');

async function readImage(request: Request): Promise<File> {
  let form: FormData;
  try {
    form = await request.formData();
  } catch {
    throw problem(400, 'malformed_request');
  }
  const file = form.get('file');
  if (!(file instanceof File)) throw invalid([violation('file', 'Ce fichier est requis.')]);
  if (file.size > 5_242_880) throw problem(413, 'payload_too_large');
  if (!IMAGE_TYPES.includes(file.type)) throw problem(415, 'unsupported_media_type');
  return file;
}

function scheduleImage(
  target: 'user_avatar' | 'artist_avatar' | 'track_cover',
  targetId: string,
  file: File,
) {
  const dropped = /invalid/i.test(file.name);
  const url =
    dropped || typeof URL.createObjectURL !== 'function' ? null : URL.createObjectURL(file);
  db.state.pendingImages.push({
    target,
    targetId,
    url: dropped ? null : url,
    applyAt: Date.now() + mockConfig.imageProcessingMs,
  });
  persist();
}

function trackOr404(id: string, viewer: MockUser | null): MockTrack {
  const tr = findTrack(id);
  if (!tr || !trackVisibleTo(tr, viewer)) throw problem(404, 'not_found');
  return tr;
}

function ownedTrack(id: string, user: MockUser): MockTrack {
  const tr = trackOr404(id, user);
  if (tr.ownerId !== user.id) throw problem(403, 'forbidden');
  return tr;
}

function ownedArtist(slug: string, user: MockUser) {
  const a = findArtistBySlug(slug);
  if (!a || !artistVisibleTo(a, user)) throw problem(404, 'not_found');
  if (a.ownerId !== user.id) throw problem(403, 'forbidden');
  return a;
}

function checkLength(
  out: Violation[],
  path: string,
  value: unknown,
  min: number,
  max: number,
  label: string,
) {
  if (typeof value !== 'string' || value.trim().length < min) {
    out.push(violation(path, `${label} est requis.`));
  } else if (value.length > max) {
    out.push(violation(path, `${label} ne doit pas dépasser ${max} caractères.`));
  }
}

export const handlers = [
  // ───────────── auth ─────────────
  http.post(`${API}/auth/register`, async ({ request }) => {
    tick();
    const body = await readJson<Record<string, unknown>>(request);
    const v: Violation[] = [];
    const email = typeof body.email === 'string' ? body.email.trim().toLowerCase() : '';
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))
      v.push(violation('email', 'Adresse e-mail invalide.'));
    if (typeof body.password !== 'string' || body.password.length < 10)
      v.push(violation('password', 'Le mot de passe doit contenir au moins 10 caractères.'));
    if (typeof body.username !== 'string' || !/^[a-z0-9_.]{3,30}$/.test(body.username))
      v.push(violation('username', '3 à 30 caractères : minuscules, chiffres, « _ » ou « . ».'));
    checkLength(v, 'displayName', body.displayName, 1, 50, 'Le nom affiché');
    if (v.length) return invalid(v);
    if (db.state.users.some((u) => u.email === email))
      return problem(409, 'email_taken', { detail: 'This email is already registered.' });
    if (db.state.users.some((u) => u.username === body.username))
      return problem(409, 'username_taken', { detail: 'This username is taken.' });
    const user: MockUser = {
      id: uuid7(),
      email,
      password: body.password as string,
      username: body.username as string,
      displayName: (body.displayName as string).trim(),
      bio: null,
      avatarUrl: null,
      createdAt: isoNow(),
      emailVerified: false,
      roles: ['ROLE_USER'],
      suspended: false,
      followersCount: 0,
      followingCount: 0,
    };
    db.state.users.push(user);
    sendMail(
      user.email,
      'Confirme ton adresse e-mail',
      `/verify-email?token=${issueAccountToken(user, 'verify_email')}`,
    );
    return openSession(user, 201);
  }),

  http.post(`${API}/auth/login`, async ({ request }) => {
    tick();
    const body = await readJson<Record<string, unknown>>(request);
    const email = typeof body.email === 'string' ? body.email.trim().toLowerCase() : '';
    const user = db.state.users.find((u) => u.email === email);
    if (!user || user.password !== body.password) return problem(401, 'invalid_credentials');
    if (user.suspended) return problem(403, 'account_suspended');
    return openSession(user);
  }),

  http.post(`${API}/auth/refresh`, async () => {
    tick();
    // The cookie travels with the request: read it now, as concurrent requests all carry it.
    const token = db.state.cookie;
    await delay(mockConfig.refreshLatencyMs);
    syncFromStorage();
    const stored = token ? db.state.refreshTokens[token] : undefined;
    const user = stored ? findUserById(stored.userId) : undefined;
    if (!token || !stored || !user) {
      db.state.cookie = null;
      persist();
      return problem(401, 'refresh_token_invalid');
    }
    if (stored.rotated) {
      // ADR 008: reuse of a rotated token → revoke the whole family.
      revokeFamilies({ familyId: stored.familyId });
      db.state.cookie = null;
      persist();
      return problem(401, 'token_reused');
    }
    stored.rotated = true;
    if (user.suspended) return problem(403, 'account_suspended');
    return openSession(user, 200, stored.familyId);
  }),

  http.post(`${API}/auth/logout`, () => {
    syncFromStorage();
    const stored = db.state.cookie ? db.state.refreshTokens[db.state.cookie] : undefined;
    if (stored) revokeFamilies({ familyId: stored.familyId });
    db.state.cookie = null;
    persist();
    return noContent();
  }),

  http.post(`${API}/auth/verify-email`, async ({ request }) => {
    const body = await readJson<{ token?: unknown }>(request);
    const user = consumeAccountToken(body.token, 'verify_email');
    user.emailVerified = true;
    persist();
    return noContent();
  }),

  http.post(`${API}/auth/password-reset`, async ({ request }) => {
    const body = await readJson<{ email?: unknown }>(request);
    const email = typeof body.email === 'string' ? body.email.trim().toLowerCase() : '';
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email))
      return invalid([violation('email', 'Adresse e-mail invalide.')]);
    const user = db.state.users.find((u) => u.email === email);
    if (user && !user.suspended) {
      sendMail(
        user.email,
        'Réinitialise ton mot de passe',
        `/reset-password?token=${issueAccountToken(user, 'password_reset')}`,
      );
      persist();
    }
    return accepted();
  }),

  http.post(`${API}/auth/password-reset/confirm`, async ({ request }) => {
    const body = await readJson<{ token?: unknown; newPassword?: unknown }>(request);
    if (typeof body.newPassword !== 'string' || body.newPassword.length < 10) {
      return invalid([
        violation('newPassword', 'Le mot de passe doit contenir au moins 10 caractères.'),
      ]);
    }
    const user = consumeAccountToken(body.token, 'password_reset');
    user.password = body.newPassword;
    user.emailVerified = true;
    revokeFamilies({ userId: user.id });
    persist();
    return noContent();
  }),

  // ───────────── me ─────────────
  http.get(`${API}/me`, ({ request }) => json(toMe(requireUser(request)))),

  http.patch(`${API}/me`, async ({ request }) => {
    const user = requireUser(request);
    const body = await readJson<Record<string, unknown>>(request);
    const allowed = ['displayName', 'bio'];
    const unknown = Object.keys(body).filter((k) => !allowed.includes(k));
    if (unknown.length)
      return invalid(unknown.map((k) => violation(k, 'Ce champ ne peut pas être modifié.')));
    const v: Violation[] = [];
    if ('displayName' in body)
      checkLength(v, 'displayName', body.displayName, 1, 50, 'Le nom affiché');
    if ('bio' in body && body.bio !== null) {
      if (typeof body.bio !== 'string' || body.bio.length > 300)
        v.push(violation('bio', 'La bio ne doit pas dépasser 300 caractères.'));
    }
    if (v.length) return invalid(v);
    if (typeof body.displayName === 'string') user.displayName = body.displayName.trim();
    if ('bio' in body) user.bio = (body.bio as string | null) || null;
    persist();
    return json(toMe(user));
  }),

  http.post(`${API}/me/email-verification`, ({ request }) => {
    const user = requireUser(request);
    if (user.emailVerified) return problem(409, 'email_already_verified');
    sendMail(
      user.email,
      'Confirme ton adresse e-mail',
      `/verify-email?token=${issueAccountToken(user, 'verify_email')}`,
    );
    persist();
    return accepted();
  }),

  http.post(`${API}/me/password`, async ({ request }) => {
    const user = requireUser(request);
    const body = await readJson<{ currentPassword?: unknown; newPassword?: unknown }>(request);
    const v: Violation[] = [];
    if (body.currentPassword !== user.password)
      v.push(violation('currentPassword', 'Mot de passe actuel incorrect.'));
    if (typeof body.newPassword !== 'string' || body.newPassword.length < 10)
      v.push(violation('newPassword', 'Le mot de passe doit contenir au moins 10 caractères.'));
    if (v.length) return invalid(v);
    user.password = body.newPassword as string;
    // ADR 008: other sessions revoked, the current one (`sid`) rotated and returned.
    const sid = currentSid(request) ?? uuid7();
    revokeFamilies({ userId: user.id, keepFamily: sid });
    revokeFamilies({ familyId: sid });
    return openSession(user, 200, sid);
  }),

  http.post(`${API}/me/avatar`, async ({ request }) => {
    const user = requireUser(request);
    requireVerified(user);
    const file = await readImage(request);
    scheduleImage('user_avatar', user.id, file);
    return json(toMe(user), 202);
  }),

  http.delete(`${API}/me/avatar`, ({ request }) => {
    const user = requireUser(request);
    user.avatarUrl = null;
    persist();
    return noContent();
  }),

  // ───────────── users ─────────────
  http.get(`${API}/users/:username`, ({ request, params }) => {
    const viewer = requireUser(request);
    const user = findUserByUsername(String(params.username));
    if (!user) return problem(404, 'not_found');
    return json(toProfile(user, viewer));
  }),

  http.get(`${API}/users/:username/tracks`, ({ request, params }) => {
    const viewer = requireUser(request);
    const user = findUserByUsername(String(params.username));
    if (!user) return problem(404, 'not_found');
    const items = db.state.tracks
      .filter((tr) => tr.ownerId === user.id && trackVisibleTo(tr, viewer))
      .sort(newestFirst)
      .map((tr) => toTrack(tr, viewer));
    return json(paginate(items, request) satisfies TrackPage);
  }),

  http.get(`${API}/users/:username/artists`, ({ request, params }) => {
    const viewer = requireUser(request);
    const user = findUserByUsername(String(params.username));
    if (!user) return problem(404, 'not_found');
    const items = db.state.artists
      .filter((a) => a.ownerId === user.id && artistVisibleTo(a, viewer))
      .sort((a, b) => a.createdAt.localeCompare(b.createdAt))
      .map((a) => toArtist(a, viewer));
    return json(items);
  }),

  // ───────────── artists ─────────────
  http.post(`${API}/artists`, async ({ request }) => {
    const user = requireUser(request);
    requireVerified(user);
    const body = await readJson<Record<string, unknown>>(request);
    const v: Violation[] = [];
    checkLength(v, 'name', body.name, 1, 80, 'Le nom');
    if (body.bio != null && (typeof body.bio !== 'string' || body.bio.length > 1000))
      v.push(violation('bio', 'La bio ne doit pas dépasser 1000 caractères.'));
    if (v.length) return invalid(v);
    if (db.state.artists.filter((a) => a.ownerId === user.id).length >= 10)
      return problem(409, 'artist_limit_reached');
    const name = (body.name as string).trim();
    const artist = {
      id: uuid7(),
      slug: slugify(name),
      name,
      bio: (body.bio as string | null | undefined) || null,
      avatarUrl: null,
      ownerId: user.id,
      moderation: 'visible' as const,
      createdAt: isoNow(),
    };
    db.state.artists.push(artist);
    persist();
    return json(toArtist(artist, user), 201);
  }),

  http.get(`${API}/artists/:slug`, ({ request, params }) => {
    const viewer = requireUser(request);
    const a = findArtistBySlug(String(params.slug));
    if (!a || !artistVisibleTo(a, viewer)) return problem(404, 'not_found');
    return json(toArtist(a, viewer));
  }),

  http.patch(`${API}/artists/:slug`, async ({ request, params }) => {
    const user = requireUser(request);
    const a = ownedArtist(String(params.slug), user);
    requireVerified(user);
    const body = await readJson<Record<string, unknown>>(request);
    const v: Violation[] = [];
    if ('name' in body) checkLength(v, 'name', body.name, 1, 80, 'Le nom');
    if (body.bio != null && (typeof body.bio !== 'string' || body.bio.length > 1000))
      v.push(violation('bio', 'La bio ne doit pas dépasser 1000 caractères.'));
    if (v.length) return invalid(v);
    if (typeof body.name === 'string') a.name = body.name.trim();
    if ('bio' in body) a.bio = (body.bio as string | null) || null;
    persist();
    return json(toArtist(a, user));
  }),

  http.delete(`${API}/artists/:slug`, ({ request, params }) => {
    const user = requireUser(request);
    const a = ownedArtist(String(params.slug), user);
    if (db.state.tracks.some((tr) => tr.artistId === a.id))
      return problem(409, 'artist_has_tracks');
    db.state.artists = db.state.artists.filter((x) => x.id !== a.id);
    persist();
    return noContent();
  }),

  http.post(`${API}/artists/:slug/avatar`, async ({ request, params }) => {
    const user = requireUser(request);
    const a = ownedArtist(String(params.slug), user);
    requireVerified(user);
    const file = await readImage(request);
    scheduleImage('artist_avatar', a.id, file);
    return json(toArtist(a, user), 202);
  }),

  http.delete(`${API}/artists/:slug/avatar`, ({ request, params }) => {
    const user = requireUser(request);
    const a = ownedArtist(String(params.slug), user);
    a.avatarUrl = null;
    persist();
    return noContent();
  }),

  http.get(`${API}/artists/:slug/tracks`, ({ request, params }) => {
    const viewer = requireUser(request);
    const a = findArtistBySlug(String(params.slug));
    if (!a || !artistVisibleTo(a, viewer)) return problem(404, 'not_found');
    const items = db.state.tracks
      .filter((tr) => tr.artistId === a.id && trackVisibleTo(tr, viewer))
      .sort(newestFirst)
      .map((tr) => toTrack(tr, viewer));
    return json(paginate(items, request) satisfies TrackPage);
  }),

  // ───────────── genres ─────────────
  http.get(`${API}/genres`, () => json(GENRES.map((g) => ({ ...g })))),

  // ───────────── tracks ─────────────
  // ADR 009 step 1: upload slot with a (fake) presigned POST policy.
  http.post(`${API}/tracks/uploads`, async ({ request }) => {
    const user = requireUser(request);
    requireVerified(user);
    const body = await readJson<Record<string, unknown>>(request);
    const filename = typeof body.filename === 'string' ? body.filename : '';
    const size = body.size;
    if (!filename || typeof size !== 'number' || !Number.isInteger(size) || size < 1)
      return invalid([violation('size', 'Taille invalide.')]);
    const ext = filename.includes('.') ? filename.split('.').pop()!.toLowerCase() : '';
    if (!UPLOAD_EXT.includes(ext) || !UPLOAD_TYPES.includes(String(body.contentType)))
      return problem(415, 'unsupported_media_type');
    const maxBytes = ext === 'mp4' ? 209_715_200 : 31_457_280;
    if (size > maxBytes) return problem(413, 'payload_too_large');
    const pending = Object.values(db.state.uploads).filter(
      (u) => u.userId === user.id && u.status === 'pending',
    );
    if (pending.length >= 10) return problem(409, 'too_many_pending_uploads');
    const id = uuid7();
    const key = `quarantine/uploads/${user.id}/${id}`;
    const now = Date.now();
    db.state.uploads[id] = {
      id,
      userId: user.id,
      key,
      filename,
      size,
      contentType: String(body.contentType),
      maxBytes,
      status: 'pending',
      createdAt: now,
      expiresAt: now + mockConfig.uploadPolicyTtlMs,
    };
    persist();
    const policy = btoa(JSON.stringify({ key, maxBytes, exp: now + mockConfig.uploadPolicyTtlMs }));
    const slot: TrackUploadSlot = {
      uploadId: id,
      method: 'POST',
      url: `${origin()}/mock-storage/wave-private`,
      fields: {
        key,
        policy,
        'x-amz-algorithm': 'AWS4-HMAC-SHA256',
        'x-amz-credential': 'mock/20261026/us-east-1/s3/aws4_request',
        'x-amz-date': '20261026T000000Z',
        'x-amz-signature': 'mock-signature',
      },
      maxBytes,
      expiresAt: isoNow(now + mockConfig.uploadPolicyTtlMs),
    };
    return json(slot, 201);
  }),

  // ADR 009 step 2: the "object storage" (S3 POST policy semantics, XML errors, 204).
  http.post('*/mock-storage/wave-private', async ({ request }) => {
    syncFromStorage();
    const s3Error = (status: number, code: string) =>
      new HttpResponse(`<?xml version="1.0"?><Error><Code>${code}</Code></Error>`, {
        status,
        headers: { 'Content-Type': 'application/xml' },
      });
    let form: FormData;
    try {
      form = await request.formData();
    } catch {
      return s3Error(400, 'MalformedPOSTRequest');
    }
    const file = form.get('file');
    let policy: { key: string; maxBytes: number; exp: number };
    try {
      policy = JSON.parse(atob(String(form.get('policy')))) as typeof policy;
    } catch {
      return s3Error(403, 'AccessDenied');
    }
    if (form.get('key') !== policy.key) return s3Error(403, 'AccessDenied');
    if (Date.now() > policy.exp) return s3Error(403, 'AccessDenied');
    if (!(file instanceof File)) return s3Error(400, 'MalformedPOSTRequest');
    if (file.size < 1 || file.size > policy.maxBytes) return s3Error(400, 'EntityTooLarge');
    db.state.storedObjects[policy.key] = { size: file.size, name: file.name };
    persist();
    return new HttpResponse(null, { status: 204 });
  }),

  // ADR 009 step 3: create the track from a completed upload.
  http.post(`${API}/tracks`, async ({ request }) => {
    const user = requireUser(request);
    requireVerified(user);
    const body = await readJson<Record<string, unknown>>(request);
    const upload = typeof body.uploadId === 'string' ? db.state.uploads[body.uploadId] : undefined;
    if (!upload || upload.userId !== user.id) return problem(404, 'not_found');
    if (upload.status === 'consumed') return problem(409, 'upload_already_used');
    if (upload.status === 'expired' || Date.now() - upload.createdAt > mockConfig.uploadMaxAgeMs)
      return problem(409, 'upload_expired');
    const object = db.state.storedObjects[upload.key];
    if (!object) return problem(409, 'upload_incomplete');

    const v: Violation[] = [];
    checkLength(v, 'title', body.title, 1, 120, 'Le titre');
    if (typeof body.genreId !== 'string' || !findGenre(body.genreId))
      v.push(violation('genreId', 'Genre inconnu.'));
    const visibility = body.visibility ?? 'public';
    if (visibility !== 'public' && visibility !== 'private')
      v.push(violation('visibility', 'Visibilité invalide.'));
    if (v.length) return invalid(v);

    const owned = db.state.artists.filter((a) => a.ownerId === user.id);
    let artist = body.artistId ? owned.find((a) => a.id === body.artistId) : undefined;
    if (body.artistId && !artist) return problem(404, 'not_found');
    if (!artist) {
      if (owned.length === 0) {
        artist = {
          id: uuid7(),
          slug: slugify(user.displayName),
          name: user.displayName,
          bio: null,
          avatarUrl: null,
          ownerId: user.id,
          moderation: 'visible',
          createdAt: isoNow(),
        };
        db.state.artists.push(artist);
      } else if (owned.length === 1) {
        artist = owned[0]!;
      } else {
        return problem(422, 'artist_required', { detail: 'Choose one of your artists.' });
      }
    }
    upload.status = 'consumed';
    const name = upload.filename.toLowerCase();
    const track: MockTrack = {
      id: uuid7(),
      title: (body.title as string).trim(),
      artistId: artist.id,
      ownerId: user.id,
      genreId: body.genreId as string,
      coverUrl: null,
      durationMs: null,
      status: 'processing',
      failureReason: null,
      visibility: visibility as 'public' | 'private',
      allowClips: body.allowClips !== false,
      moderation: 'visible',
      playCount: 0,
      likeCount: 0,
      createdAt: isoNow(),
      audio: pickAudio(upload.filename + Date.now()),
      processAt: Date.now() + mockConfig.trackProcessingMs,
      // Fake worker outcomes driven by the file name (demo/testing).
      processOutcome: name.includes('fail')
        ? 'invalid_media'
        : name.includes('long')
          ? 'too_long'
          : name.includes('codec')
            ? 'unsupported_codec'
            : null,
    };
    db.state.tracks.push(track);
    persist();
    return HttpResponse.json(toTrack(track, user), {
      status: 202,
      headers: { Location: `/api/v1/tracks/${track.id}` },
    });
  }),

  http.get(`${API}/tracks/:trackId`, ({ request, params }) => {
    const viewer = requireUser(request);
    return json(toTrack(trackOr404(String(params.trackId), viewer), viewer));
  }),

  http.patch(`${API}/tracks/:trackId`, async ({ request, params }) => {
    const user = requireUser(request);
    const tr = ownedTrack(String(params.trackId), user);
    const body = await readJson<Record<string, unknown>>(request);
    const v: Violation[] = [];
    if ('title' in body) checkLength(v, 'title', body.title, 1, 120, 'Le titre');
    if ('genreId' in body && (typeof body.genreId !== 'string' || !findGenre(body.genreId)))
      v.push(violation('genreId', 'Genre inconnu.'));
    if ('visibility' in body && body.visibility !== 'public' && body.visibility !== 'private')
      v.push(violation('visibility', 'Visibilité invalide.'));
    if (v.length) return invalid(v);
    if ('artistId' in body) {
      const a = db.state.artists.find((x) => x.id === body.artistId && x.ownerId === user.id);
      if (!a) return invalid([violation('artistId', 'Artiste inconnu.')]);
      tr.artistId = a.id;
    }
    if (typeof body.title === 'string') tr.title = body.title.trim();
    if (typeof body.genreId === 'string') tr.genreId = body.genreId;
    if (body.visibility === 'public' || body.visibility === 'private')
      tr.visibility = body.visibility;
    if (typeof body.allowClips === 'boolean') tr.allowClips = body.allowClips;
    persist();
    return json(toTrack(tr, user));
  }),

  http.delete(`${API}/tracks/:trackId`, ({ request, params }) => {
    const user = requireUser(request);
    const tr = ownedTrack(String(params.trackId), user);
    db.state.tracks = db.state.tracks.filter((x) => x.id !== tr.id);
    persist();
    return noContent();
  }),

  http.post(`${API}/tracks/:trackId/cover`, async ({ request, params }) => {
    const user = requireUser(request);
    const tr = ownedTrack(String(params.trackId), user);
    requireVerified(user);
    const file = await readImage(request);
    scheduleImage('track_cover', tr.id, file);
    return json(toTrack(tr, user), 202);
  }),

  http.delete(`${API}/tracks/:trackId/cover`, ({ request, params }) => {
    const user = requireUser(request);
    const tr = ownedTrack(String(params.trackId), user);
    tr.coverUrl = null;
    persist();
    return noContent();
  }),

  http.get(`${API}/tracks/:trackId/stream-url`, ({ request, params }) => {
    const viewer = requireUser(request);
    const tr = trackOr404(String(params.trackId), viewer);
    if (tr.status !== 'ready') return problem(409, 'track_not_ready');
    const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
    const body: StreamUrl = {
      url: `${origin}/mock-audio/${tr.audio}.m4a?sig=${randomToken().slice(0, 12)}`,
      expiresAt: isoNow(Date.now() + mockConfig.streamTtlMs),
    };
    return HttpResponse.json(body, { headers: { 'Cache-Control': 'no-store' } });
  }),

  http.post(`${API}/tracks/:trackId/listens`, async ({ request, params }) => {
    const user = requireUser(request);
    const tr = trackOr404(String(params.trackId), user);
    const body = await readJson<Partial<ListenRequest>>(request);
    if (
      typeof body.listenedMs !== 'number' ||
      body.listenedMs < 0 ||
      body.listenedMs > 3_600_000 ||
      (body.source !== 'player' && body.source !== 'feed')
    ) {
      return invalid([violation('listenedMs', 'Valeur invalide.')]);
    }
    // DATA_MODEL §11 counting rules (player source).
    const ref = tr.durationMs ?? 0;
    const windowMs = Math.max(30_000, ref / 2);
    const recent = db.state.listens.some(
      (l) => l.userId === user.id && l.trackId === tr.id && Date.now() - l.at < windowMs,
    );
    const counted =
      (body.listenedMs >= 30_000 || body.listenedMs >= ref * 0.5) &&
      body.listenedMs <= ref + 2_000 &&
      !recent;
    if (counted) {
      tr.playCount += 1;
      db.state.listens.push({ userId: user.id, trackId: tr.id, at: Date.now() });
      persist();
    }
    return json({ counted, playCount: tr.playCount } satisfies ListenResult);
  }),
];
