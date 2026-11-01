// MSW handlers for phase 2: playlists, likes, follows, user search (openapi + W-27 rules).
import { http } from 'msw';
import type { FollowState, LikeState, PlaylistItem, Violation } from '@/api/types';
import type { MockUser } from './fixtures';
import {
  db,
  findTrack,
  findUserByUsername,
  followersCount,
  isoNow,
  persist,
  playlistVisibleTo,
  tick,
  toPlaylist,
  toTrack,
  toUserListItem,
  trackVisibleTo,
  uuid7,
} from './db';
import {
  invalid,
  json,
  noContent,
  paginate,
  problem,
  readJson,
  requireUser,
  violation,
} from './handlerUtils';
import type { MockPlaylist } from './socialFixtures';

const API = '*/api/v1';
const byDateDesc = (a: { at: string }, b: { at: string }) => b.at.localeCompare(a.at);

function playlistOr404(id: string, viewer: MockUser): MockPlaylist {
  const p = db.state.playlists.find((x) => x.id === id);
  if (!p || !playlistVisibleTo(p, viewer)) throw problem(404, 'not_found');
  return p;
}
function ownedPlaylist(id: string, user: MockUser): MockPlaylist {
  const p = playlistOr404(id, user);
  if (p.ownerId !== user.id) throw problem(403, 'forbidden');
  return p;
}

function validatePlaylist(body: Record<string, unknown>, partial: boolean): Violation[] {
  const v: Violation[] = [];
  if (!partial || 'name' in body) {
    if (typeof body.name !== 'string' || !body.name.trim())
      v.push(violation('name', 'Le nom est requis.'));
    else if (body.name.length > 100) v.push(violation('name', '100 caractères maximum.'));
  }
  if (
    body.description != null &&
    (typeof body.description !== 'string' || body.description.length > 300)
  )
    v.push(violation('description', '300 caractères maximum.'));
  if ('isPublic' in body && typeof body.isPublic !== 'boolean')
    v.push(violation('isPublic', 'Valeur invalide.'));
  return v;
}

function likeState(kind: 'track' | 'playlist', id: string, viewer: MockUser): LikeState {
  if (kind === 'playlist') {
    const p = db.state.playlists.find((x) => x.id === id)!;
    const out = toPlaylist(p, viewer);
    return { liked: out.likedByMe, likeCount: out.likeCount };
  }
  const t = toTrack(findTrack(id)!, viewer);
  return { liked: t.likedByMe, likeCount: t.likeCount };
}

function toggleLike(kind: 'track' | 'playlist', like: boolean) {
  return ({ request, params }: { request: Request; params: Record<string, unknown> }) => {
    const user = requireUser(request);
    const id = String(params.id);
    if (kind === 'track') {
      const t = findTrack(id);
      if (!t || !trackVisibleTo(t, user)) return problem(404, 'not_found');
    } else playlistOr404(id, user);
    const list = kind === 'track' ? db.state.trackLikes : db.state.playlistLikes;
    const has = list.some((l) => l.userId === user.id && l.targetId === id);
    if (like && !has) list.push({ userId: user.id, targetId: id, at: isoNow() });
    if (!like && has) {
      const next = list.filter((l) => !(l.userId === user.id && l.targetId === id));
      if (kind === 'track') db.state.trackLikes = next;
      else db.state.playlistLikes = next;
    }
    persist();
    return json(likeState(kind, id, user));
  };
}

function toggleFollow(follow: boolean) {
  return ({ request, params }: { request: Request; params: Record<string, unknown> }) => {
    const user = requireUser(request);
    const target = findUserByUsername(String(params.username));
    if (!target) return problem(404, 'not_found');
    if (follow && target.id === user.id) return problem(422, 'cannot_follow_self');
    const has = db.state.follows.some(
      (f) => f.followerId === user.id && f.followeeId === target.id,
    );
    if (follow && !has)
      db.state.follows.push({ followerId: user.id, followeeId: target.id, at: isoNow() });
    if (!follow && has)
      db.state.follows = db.state.follows.filter(
        (f) => !(f.followerId === user.id && f.followeeId === target.id),
      );
    persist();
    return json({
      following: follow,
      followersCount: followersCount(target.id),
    } satisfies FollowState);
  };
}

const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/** Rough stand-in for FTS + trigram: rank exact > prefix (username or any displayName word) > substring. */
function userSearchRank(u: MockUser, q: string): number | null {
  const name = fold(u.username);
  const words = fold(u.displayName).split(/\s+/);
  if (name === q) return 0;
  if (name.startsWith(q) || words.some((w) => w.startsWith(q)) || fold(u.displayName).startsWith(q))
    return 1;
  if (name.includes(q) || fold(u.displayName).includes(q)) return 2;
  return null;
}

export const socialHandlers = [
  // ───────────── playlists ─────────────
  http.post(`${API}/playlists`, async ({ request }) => {
    const user = requireUser(request);
    const body = await readJson<Record<string, unknown>>(request);
    const v = validatePlaylist(body, false);
    if (v.length) return invalid(v);
    const p: MockPlaylist = {
      id: uuid7(),
      name: (body.name as string).trim(),
      description: (body.description as string | null | undefined)?.trim() || null,
      isPublic: body.isPublic !== false,
      ownerId: user.id,
      moderation: 'visible',
      createdAt: isoNow(),
      updatedAt: isoNow(),
    };
    db.state.playlists.push(p);
    db.state.playlistItems[p.id] = [];
    persist();
    return json(toPlaylist(p, user), 201);
  }),

  http.get(`${API}/playlists/:id`, ({ request, params }) => {
    const user = requireUser(request);
    return json(toPlaylist(playlistOr404(String(params.id), user), user));
  }),

  http.patch(`${API}/playlists/:id`, async ({ request, params }) => {
    const user = requireUser(request);
    const p = ownedPlaylist(String(params.id), user);
    const body = await readJson<Record<string, unknown>>(request);
    const v = validatePlaylist(body, true);
    if (v.length) return invalid(v);
    if (typeof body.name === 'string') p.name = body.name.trim();
    if ('description' in body) p.description = (body.description as string | null)?.trim() || null;
    if (typeof body.isPublic === 'boolean') p.isPublic = body.isPublic;
    p.updatedAt = isoNow();
    persist();
    return json(toPlaylist(p, user));
  }),

  http.delete(`${API}/playlists/:id`, ({ request, params }) => {
    const user = requireUser(request);
    const p = ownedPlaylist(String(params.id), user);
    db.state.playlists = db.state.playlists.filter((x) => x.id !== p.id);
    delete db.state.playlistItems[p.id];
    db.state.playlistLikes = db.state.playlistLikes.filter((l) => l.targetId !== p.id);
    persist();
    return noContent();
  }),

  http.get(`${API}/playlists/:id/items`, ({ request, params }) => {
    const user = requireUser(request);
    const p = playlistOr404(String(params.id), user);
    const items: PlaylistItem[] = (db.state.playlistItems[p.id] ?? []).map((i, position) => {
      const t = findTrack(i.trackId);
      return {
        trackId: i.trackId,
        position,
        addedAt: i.addedAt,
        track: t && trackVisibleTo(t, user) ? toTrack(t, user) : null,
      };
    });
    return json(paginate(items, request, 100, 50));
  }),

  http.post(`${API}/playlists/:id/items`, async ({ request, params }) => {
    const user = requireUser(request);
    const p = ownedPlaylist(String(params.id), user);
    const body = await readJson<{ trackId?: unknown }>(request);
    const t = typeof body.trackId === 'string' ? findTrack(body.trackId) : undefined;
    if (!t || !trackVisibleTo(t, user)) return problem(404, 'not_found');
    if (t.status !== 'ready') return problem(409, 'track_not_ready');
    const items = (db.state.playlistItems[p.id] ??= []);
    if (items.some((i) => i.trackId === t.id)) return problem(409, 'track_already_in_playlist');
    if (items.length >= 500) return problem(409, 'playlist_full');
    const addedAt = isoNow();
    items.push({ trackId: t.id, addedAt });
    p.updatedAt = addedAt;
    persist();
    return json(
      {
        trackId: t.id,
        position: items.length - 1,
        addedAt,
        track: toTrack(t, user),
      } satisfies PlaylistItem,
      201,
    );
  }),

  http.delete(`${API}/playlists/:id/items/:trackId`, ({ request, params }) => {
    const user = requireUser(request);
    const p = ownedPlaylist(String(params.id), user);
    const items = db.state.playlistItems[p.id] ?? [];
    if (!items.some((i) => i.trackId === params.trackId)) return problem(404, 'not_found');
    db.state.playlistItems[p.id] = items.filter((i) => i.trackId !== params.trackId);
    persist();
    return noContent();
  }),

  http.put(`${API}/playlists/:id/order`, async ({ request, params }) => {
    tick();
    const user = requireUser(request);
    const p = ownedPlaylist(String(params.id), user);
    const body = await readJson<{ trackIds?: unknown }>(request);
    if (!Array.isArray(body.trackIds)) return problem(400, 'malformed_request');
    const ids = body.trackIds as string[];
    const items = db.state.playlistItems[p.id] ?? [];
    const current = new Set(items.map((i) => i.trackId));
    const same =
      ids.length === items.length &&
      new Set(ids).size === ids.length &&
      ids.every((id) => current.has(id));
    if (!same) return problem(409, 'playlist_order_mismatch');
    db.state.playlistItems[p.id] = ids.map((id) => items.find((i) => i.trackId === id)!);
    p.updatedAt = isoNow();
    persist();
    return noContent();
  }),

  http.put(`${API}/playlists/:id/like`, toggleLike('playlist', true)),
  http.delete(`${API}/playlists/:id/like`, toggleLike('playlist', false)),
  http.put(`${API}/tracks/:id/like`, toggleLike('track', true)),
  http.delete(`${API}/tracks/:id/like`, toggleLike('track', false)),

  http.get(`${API}/me/likes/tracks`, ({ request }) => {
    const user = requireUser(request);
    const items = db.state.trackLikes
      .filter((l) => l.userId === user.id)
      .sort(byDateDesc)
      .map((l) => findTrack(l.targetId))
      .filter((t) => t && trackVisibleTo(t, user))
      .map((t) => toTrack(t!, user));
    return json(paginate(items, request));
  }),

  http.get(`${API}/me/likes/playlists`, ({ request }) => {
    const user = requireUser(request);
    const items = db.state.playlistLikes
      .filter((l) => l.userId === user.id)
      .sort(byDateDesc)
      .map((l) => db.state.playlists.find((p) => p.id === l.targetId))
      .filter((p) => p && playlistVisibleTo(p, user))
      .map((p) => toPlaylist(p!, user));
    return json(paginate(items, request));
  }),

  http.get(`${API}/users/:username/playlists`, ({ request, params }) => {
    const viewer = requireUser(request);
    const owner = findUserByUsername(String(params.username));
    if (!owner) return problem(404, 'not_found');
    const items = db.state.playlists
      .filter((p) => p.ownerId === owner.id && playlistVisibleTo(p, viewer))
      .sort((a, b) => b.createdAt.localeCompare(a.createdAt))
      .map((p) => toPlaylist(p, viewer));
    return json(paginate(items, request));
  }),

  // ───────────── follow ─────────────
  http.put(`${API}/users/:username/follow`, toggleFollow(true)),
  http.delete(`${API}/users/:username/follow`, toggleFollow(false)),

  http.get(`${API}/users/:username/followers`, ({ request, params }) => {
    const viewer = requireUser(request);
    const u = findUserByUsername(String(params.username));
    if (!u) return problem(404, 'not_found');
    const items = db.state.follows
      .filter((f) => f.followeeId === u.id)
      .sort(byDateDesc)
      .map((f) => db.state.users.find((x) => x.id === f.followerId))
      .filter((x): x is MockUser => !!x)
      .map((x) => toUserListItem(x, viewer));
    return json(paginate(items, request));
  }),

  http.get(`${API}/users/:username/following`, ({ request, params }) => {
    const viewer = requireUser(request);
    const u = findUserByUsername(String(params.username));
    if (!u) return problem(404, 'not_found');
    const items = db.state.follows
      .filter((f) => f.followerId === u.id)
      .sort(byDateDesc)
      .map((f) => db.state.users.find((x) => x.id === f.followeeId))
      .filter((x): x is MockUser => !!x)
      .map((x) => toUserListItem(x, viewer));
    return json(paginate(items, request));
  }),

  // ───────────── search (phase 2: users only, W-27) ─────────────
  http.get(`${API}/search`, ({ request }) => {
    const viewer = requireUser(request);
    const url = new URL(request.url);
    const q = fold((url.searchParams.get('q') ?? '').trim());
    if (url.searchParams.get('type') !== 'users')
      return invalid([violation('type', 'Seule la recherche de personnes est disponible.')]);
    if (q.length < 2 || q.length > 100) return invalid([violation('q', '2 à 100 caractères.')]);
    const ranked = db.state.users
      .filter((u) => u.id !== viewer.id && !u.suspended)
      .map((u) => ({ u, rank: userSearchRank(u, q) }))
      .filter((x): x is { u: MockUser; rank: number } => x.rank !== null)
      .sort(
        (a, b) =>
          a.rank - b.rank ||
          followersCount(b.u.id) - followersCount(a.u.id) ||
          a.u.id.localeCompare(b.u.id),
      )
      .slice(0, 200)
      .map((x) => toUserListItem(x.u, viewer));
    return json({ users: paginate(ranked, request) });
  }),
];
