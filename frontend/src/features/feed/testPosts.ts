// Test fixtures for the feed (Vitest only).
import type { Post, Track } from '@/api/types';

export const FAR = '2099-01-01T00:00:00Z';

export function makePost(n: number, over: Partial<Post> = {}): Post {
  const id = `0192bbbb-0000-7000-8000-${String(n).padStart(12, '0')}`;
  const track = {
    id: `0192cccc-0000-7000-8000-${String(n).padStart(12, '0')}`,
    title: `Morceau ${n}`,
    artist: { id: 'a', slug: 'nour', name: 'Nour', avatarUrl: null },
    owner: { id: 'u', username: 'nour', displayName: 'Nour', avatarUrl: null },
    genre: { id: 'rap', name: 'Rap' },
    coverUrl: null,
    durationMs: 180_000,
    status: 'ready',
    failureReason: null,
    visibility: 'public',
    allowClips: true,
    moderation: 'visible',
    playCount: 0,
    likeCount: 0,
    likedByMe: false,
    isOwner: false,
    canClip: true,
    createdAt: '2026-11-01T00:00:00Z',
  } as unknown as Track;
  return {
    id,
    author: { id: 'u', username: 'nour', displayName: 'Nour', avatarUrl: null },
    track,
    clipStartMs: 0,
    clipDurationMs: 20_000,
    clip: { url: `https://wave.test/stream/clip-${n}`, expiresAt: FAR },
    visual: null,
    caption: `Légende ${n} #rap`,
    hashtags: ['rap'],
    status: 'ready',
    failureReason: null,
    moderation: 'visible',
    likeCount: 3,
    commentCount: 1,
    viewCount: 0,
    likedByMe: false,
    isOwner: false,
    createdAt: '2026-11-01T00:00:00Z',
    ...over,
  } as Post;
}
