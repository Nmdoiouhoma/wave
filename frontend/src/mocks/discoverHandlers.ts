// MSW handler for GET /discover (W-43 / W-87): same rules as the contract, over the mock db.
import { http } from 'msw';
import type { DiscoverHome, DiscoverForYouTrack } from '@/api/types';
import { GENRES } from './fixtures';
import { db, followersCount, isFollowing, toTrack, toUserListItem, trackVisibleTo } from './db';
import { json, requireUser } from './handlerUtils';

const API = '*/api/v1';

const HASHTAGS = [
  { name: 'lofi', postCount: 128 },
  { name: 'nuitblanche', postCount: 96 },
  { name: 'freestyle', postCount: 74 },
  { name: 'afrobeats', postCount: 51 },
  { name: 'jazz', postCount: 33 },
];

export const discoverHandlers = [
  http.get(`${API}/discover`, async ({ request }) => {
    const me = requireUser(request);
    const visible = db.state.tracks
      .filter((t) => t.status === 'ready' && t.visibility === 'public' && trackVisibleTo(t, me))
      .map((t) => toTrack(t, me));
    const others = visible.filter((t) => !t.isOwner);
    const forYouTracks: DiscoverForYouTrack[] = others
      .filter((t) => !t.likedByMe)
      .slice(0, 4)
      .map((t) => ({ track: t, reasonType: 'artist', reasonLabel: t.artist.name }));
    const body: DiscoverHome = {
      forYouTracks,
      newTracks: [...others].sort((a, b) => b.createdAt.localeCompare(a.createdAt)).slice(0, 12),
      trendingTracks: [...visible].sort((a, b) => b.playCount - a.playCount).slice(0, 10),
      genres: GENRES.slice(0, 12).map((g) => ({ id: g.id, name: g.name })),
      suggestedUsers: db.state.users
        .filter((u) => u.id !== me.id && !isFollowing(me.id, u.id))
        .sort((a, b) => followersCount(b.id) - followersCount(a.id))
        .slice(0, 6)
        .map((u) => toUserListItem(u, me)),
      trendingHashtags: HASHTAGS,
    };
    return json(body);
  }),
];
