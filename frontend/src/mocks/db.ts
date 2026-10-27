// In-memory mock backend state. Persisted to localStorage in the browser so a reload keeps
// the session (the fake refresh "cookie") and created content.
import type { Artist, FailureReason, Me, Track, UserProfile, UserSummary } from '@/api/types';
import {
  GENRES,
  MOCK_AUDIO_DURATION_MS,
  type MockArtist,
  type MockAudio,
  type MockTrack,
  type MockUser,
  seedArtists,
  seedTracks,
  seedUsers,
} from './fixtures';

export interface AccountToken {
  userId: string;
  purpose: 'verify_email' | 'password_reset';
  expiresAt: number;
  used: boolean;
}

export interface MockUpload {
  id: string;
  userId: string;
  key: string;
  filename: string;
  size: number;
  contentType: string;
  maxBytes: number;
  status: 'pending' | 'consumed' | 'expired';
  createdAt: number;
  expiresAt: number;
}

export interface MockRefreshToken {
  userId: string;
  familyId: string;
  rotated: boolean;
}

export interface MailEntry {
  to: string;
  subject: string;
  link: string;
  at: string;
}

interface PendingImage {
  target: 'user_avatar' | 'artist_avatar' | 'track_cover';
  targetId: string;
  url: string | null; // null = invalid image, dropped silently
  applyAt: number;
}

export interface MockState {
  users: MockUser[];
  artists: MockArtist[];
  tracks: MockTrack[];
  /** ADR 008: refresh tokens rotate inside a family; rotated ones are kept to detect reuse. */
  refreshTokens: Record<string, MockRefreshToken>;
  /** Simulates the httpOnly `wave_refresh` cookie of this browser. */
  cookie: string | null;
  accountTokens: Record<string, AccountToken>;
  outbox: MailEntry[];
  pendingImages: PendingImage[];
  listens: { userId: string; trackId: string; at: number }[];
  /** ADR 009 `track_upload` rows. */
  uploads: Record<string, MockUpload>;
  /** Objects written to the mock storage by presigned POSTs, by key. */
  storedObjects: Record<string, { size: number; name: string }>;
}

export const mockConfig = {
  accessTtlMs: 900_000,
  streamTtlMs: 300_000,
  /** Fake worker latency. */
  trackProcessingMs: 8_000,
  /** ADR 009: presigned policy TTL and upload consumption window. */
  uploadPolicyTtlMs: 15 * 60_000,
  uploadMaxAgeMs: 2 * 3600_000,
  /** Server time between reading the refresh cookie and rotating it (makes races observable). */
  refreshLatencyMs: 20,
  imageProcessingMs: 3_000,
  persist: typeof window !== 'undefined' && typeof localStorage !== 'undefined',
};

const STORAGE_KEY = 'wave-mock-db-v1';

function seed(): MockState {
  return {
    users: seedUsers(),
    artists: seedArtists(),
    tracks: seedTracks(),
    refreshTokens: {},
    cookie: null,
    accountTokens: {},
    outbox: [],
    pendingImages: [],
    listens: [],
    uploads: {},
    storedObjects: {},
  };
}

function load(): MockState {
  if (!mockConfig.persist) return seed();
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return seed();
    const state = JSON.parse(raw) as MockState;
    state.uploads ??= {};
    state.storedObjects ??= {};
    // Blob URLs do not survive a reload.
    const unblob = (u: string | null) => (u?.startsWith('blob:') ? null : u);
    state.users.forEach((u) => (u.avatarUrl = unblob(u.avatarUrl)));
    state.artists.forEach((a) => (a.avatarUrl = unblob(a.avatarUrl)));
    state.tracks.forEach((tr) => (tr.coverUrl = unblob(tr.coverUrl)));
    return state;
  } catch {
    return seed();
  }
}

export const db: { state: MockState } = { state: load() };

/** Several tabs share one mock backend through localStorage: re-read it before each request. */
export function syncFromStorage(): void {
  if (!mockConfig.persist) return;
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) db.state = JSON.parse(raw) as MockState;
  } catch {
    /* keep the in-memory state */
  }
}

export function persist(): void {
  if (!mockConfig.persist) return;
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(db.state));
  } catch {
    /* quota or private mode: mocks keep working in memory */
  }
}

export function resetDb(): void {
  db.state = seed();
  persist();
}

// ───────────────────────── ids, tokens, time ─────────────────────────

export function uuid7(): string {
  const ts = Date.now().toString(16).padStart(12, '0');
  const rand = Array.from(crypto.getRandomValues(new Uint8Array(10)), (b) =>
    b.toString(16).padStart(2, '0'),
  ).join('');
  return `${ts.slice(0, 8)}-${ts.slice(8, 12)}-7${rand.slice(0, 3)}-${((parseInt(rand.slice(3, 4), 16) & 0x3) | 0x8).toString(16)}${rand.slice(4, 7)}-${rand.slice(7, 19)}`;
}

export function randomToken(): string {
  return Array.from(crypto.getRandomValues(new Uint8Array(16)), (b) =>
    b.toString(16).padStart(2, '0'),
  ).join('');
}

export function isoNow(ms = Date.now()): string {
  return new Date(ms).toISOString().replace(/\.\d+Z$/, 'Z');
}

/** `mock.{userId}.{expMs}.{sid}` — `sid` = refresh family, like the real JWT claim. */
export function issueAccessToken(userId: string, sid: string): string {
  return `mock.${userId}.${Date.now() + mockConfig.accessTtlMs}.${sid}`;
}

export function parseAccessToken(
  token: string,
): { userId: string; exp: number; sid: string | null } | null {
  const m = /^mock\.([0-9a-f-]+)\.(\d+)(?:\.([0-9a-f-]+))?$/.exec(token);
  return m ? { userId: m[1]!, exp: Number(m[2]), sid: m[3] ?? null } : null;
}

/** Deletes refresh families: of a user (except `keepFamily`), or one family. */
export function revokeFamilies(filter: {
  userId?: string;
  familyId?: string;
  keepFamily?: string;
}) {
  for (const [k, t] of Object.entries(db.state.refreshTokens)) {
    const match =
      (filter.familyId !== undefined && t.familyId === filter.familyId) ||
      (filter.userId !== undefined &&
        t.userId === filter.userId &&
        t.familyId !== filter.keepFamily);
    if (match) delete db.state.refreshTokens[k];
  }
}

export function slugify(name: string): string {
  const base =
    name
      .normalize('NFD')
      .replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 70) || 'artiste';
  let slug = base;
  let n = 2;
  while (db.state.artists.some((a) => a.slug === slug)) slug = `${base}-${n++}`;
  return slug;
}

export function pickAudio(seedStr: string): MockAudio {
  const all: MockAudio[] = ['tone-a', 'tone-b', 'tone-c'];
  let h = 0;
  for (const c of seedStr) h = (h * 31 + c.charCodeAt(0)) >>> 0;
  return all[h % all.length]!;
}

/** Applies fake-worker transitions that are due (called at the start of every handler). */
export function tick(now = Date.now()): void {
  syncFromStorage();
  let changed = false;
  for (const tr of db.state.tracks) {
    if (tr.status === 'processing' && tr.processAt !== undefined && tr.processAt <= now) {
      const outcome = tr.processOutcome ?? null;
      if (outcome) {
        tr.status = 'failed';
        tr.failureReason = outcome;
      } else {
        tr.status = 'ready';
        tr.durationMs = MOCK_AUDIO_DURATION_MS[tr.audio];
      }
      delete tr.processAt;
      delete tr.processOutcome;
      changed = true;
    }
  }
  const due = db.state.pendingImages.filter((p) => p.applyAt <= now);
  if (due.length) {
    for (const p of due) {
      if (p.url === null) continue;
      if (p.target === 'user_avatar') {
        const u = db.state.users.find((x) => x.id === p.targetId);
        if (u) u.avatarUrl = p.url;
      } else if (p.target === 'artist_avatar') {
        const a = db.state.artists.find((x) => x.id === p.targetId);
        if (a) a.avatarUrl = p.url;
      } else {
        const tr = db.state.tracks.find((x) => x.id === p.targetId);
        if (tr) tr.coverUrl = p.url;
      }
    }
    db.state.pendingImages = db.state.pendingImages.filter((p) => p.applyAt > now);
    changed = true;
  }
  if (changed) persist();
}

// ───────────────────────── lookups & rules ─────────────────────────

export const findUserById = (id: string) => db.state.users.find((u) => u.id === id);
export const findUserByUsername = (username: string) =>
  db.state.users.find((u) => u.username === username);
export const findArtistBySlug = (slug: string) => db.state.artists.find((a) => a.slug === slug);
export const findArtistById = (id: string) => db.state.artists.find((a) => a.id === id);
export const findTrack = (id: string) => db.state.tracks.find((tr) => tr.id === id);
export const findGenre = (id: string) => GENRES.find((g) => g.id === id);

export function trackVisibleTo(tr: MockTrack, viewer: MockUser | null): boolean {
  if (viewer && tr.ownerId === viewer.id) return true;
  return tr.status === 'ready' && tr.visibility === 'public' && tr.moderation === 'visible';
}

export function artistVisibleTo(a: MockArtist, viewer: MockUser | null): boolean {
  return a.moderation === 'visible' || (viewer !== null && a.ownerId === viewer.id);
}

// ───────────────────────── mappers (typed by the generated schema) ─────────────────────────

export function toSummary(u: MockUser): UserSummary {
  return { id: u.id, username: u.username, displayName: u.displayName, avatarUrl: u.avatarUrl };
}

export function toProfile(u: MockUser, viewer: MockUser | null): UserProfile {
  return {
    ...toSummary(u),
    bio: u.bio,
    followersCount: u.followersCount,
    followingCount: u.followingCount,
    createdAt: u.createdAt,
    isMe: viewer?.id === u.id,
    isFollowedByMe: false,
  };
}

export function toMe(u: MockUser): Me {
  return {
    ...toProfile(u, u),
    email: u.email,
    emailVerified: u.emailVerified,
    roles: u.roles,
  };
}

export function toArtist(a: MockArtist, viewer: MockUser | null): Artist {
  const owner = findUserById(a.ownerId)!;
  return {
    id: a.id,
    slug: a.slug,
    name: a.name,
    avatarUrl: a.avatarUrl,
    bio: a.bio,
    trackCount: db.state.tracks.filter(
      (tr) =>
        tr.artistId === a.id &&
        tr.status === 'ready' &&
        tr.visibility === 'public' &&
        tr.moderation === 'visible',
    ).length,
    owner: toSummary(owner),
    moderation: a.moderation,
    isOwner: viewer?.id === a.ownerId,
    createdAt: a.createdAt,
  };
}

export function toTrack(tr: MockTrack, viewer: MockUser | null): Track {
  const artist = findArtistById(tr.artistId)!;
  const owner = findUserById(tr.ownerId)!;
  const genre = findGenre(tr.genreId) ?? { id: tr.genreId, name: tr.genreId };
  const isOwner = viewer?.id === tr.ownerId;
  return {
    id: tr.id,
    title: tr.title,
    artist: { id: artist.id, slug: artist.slug, name: artist.name, avatarUrl: artist.avatarUrl },
    owner: toSummary(owner),
    genre: { id: genre.id, name: genre.name },
    coverUrl: tr.coverUrl,
    durationMs: tr.durationMs,
    status: tr.status,
    // Contract enum lags FB-10 `no_audio`: the payload is what the backend will send.
    failureReason: tr.failureReason as FailureReason,
    visibility: tr.visibility,
    allowClips: tr.allowClips,
    moderation: tr.moderation,
    playCount: tr.playCount,
    likeCount: tr.likeCount,
    likedByMe: false,
    isOwner,
    canClip:
      tr.status === 'ready' &&
      tr.moderation === 'visible' &&
      (isOwner || (tr.visibility === 'public' && tr.allowClips)),
    createdAt: tr.createdAt,
  };
}

export function sendMail(to: string, subject: string, path: string): void {
  const origin = typeof window !== 'undefined' ? window.location.origin : 'http://localhost';
  const entry = { to, subject, link: `${origin}${path}`, at: isoNow() };
  db.state.outbox.unshift(entry);
  db.state.outbox = db.state.outbox.slice(0, 20);
  if (mockConfig.persist) console.info(`[mock mail] ${subject} → ${to}: ${entry.link}`);
}
