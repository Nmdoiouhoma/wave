// Phase 2 seed: fans (for infinite scroll), follows, playlists, likes.
import { DEMO_PASSWORD, USER_IDS, type MockUser } from './fixtures';

export interface MockPlaylist {
  id: string;
  name: string;
  description: string | null;
  isPublic: boolean;
  ownerId: string;
  moderation: 'visible' | 'hidden';
  createdAt: string;
  updatedAt: string;
}
export interface MockPlaylistItem {
  trackId: string;
  addedAt: string;
}
export interface MockLike {
  userId: string;
  targetId: string;
  at: string;
}
export interface MockFollow {
  followerId: string;
  followeeId: string;
  at: string;
}
/** FB-18 `close_friend`: one list per owner, members must follow the owner. */
export interface MockCloseFriend {
  ownerId: string;
  friendId: string;
  at: string;
}

const FIRST = [
  'Camille',
  'Hugo',
  'Inès',
  'Lucas',
  'Manon',
  'Nathan',
  'Chloé',
  'Théo',
  'Jade',
  'Louis',
  'Zoé',
  'Adam',
  'Élodie',
  'Rayan',
  'Léna',
  'Sacha',
  'Maëlle',
  'Noah',
  'Anaïs',
  'Yanis',
];
const LAST = [
  'Durand',
  'Moreau',
  'Lefèvre',
  'Garnier',
  'Roux',
  'Faure',
  'Girard',
  'Bonnet',
  'Mercier',
  'Blanc',
];

const fanId = (i: number) => `0192f3a0-0000-7000-8000-1000000000${String(i).padStart(2, '0')}`;

export function seedFans(): MockUser[] {
  return Array.from({ length: 40 }, (_, k) => {
    const i = k + 1;
    const first = FIRST[k % FIRST.length]!;
    const last = LAST[k % LAST.length]!;
    return {
      id: fanId(i),
      email: `fan${i}@wave.local`,
      password: DEMO_PASSWORD,
      username: `${first.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()}.${String(i).padStart(2, '0')}`,
      displayName: `${first} ${last}`,
      bio: null,
      avatarUrl: null,
      createdAt: '2026-09-15T10:00:00Z',
      emailVerified: true,
      roles: ['ROLE_USER'],
      suspended: false,
      followersCount: 0,
      followingCount: 0,
    } satisfies MockUser;
  });
}

const at = (minutesAgo: number) =>
  new Date(Date.parse('2026-10-28T12:00:00Z') - minutesAgo * 60_000)
    .toISOString()
    .replace(/\.\d+Z$/, 'Z');

export function seedFollows(): MockFollow[] {
  const follows: MockFollow[] = [];
  for (let i = 1; i <= 40; i++) {
    follows.push({ followerId: fanId(i), followeeId: USER_IDS.lea, at: at(i * 7) });
    if (i % 2 === 0)
      follows.push({ followerId: fanId(i), followeeId: USER_IDS.kenji, at: at(i * 5) });
  }
  follows.push({ followerId: USER_IDS.lea, followeeId: USER_IDS.kenji, at: at(3) });
  follows.push({ followerId: USER_IDS.nour, followeeId: USER_IDS.lea, at: at(2) });
  return follows;
}

/** Nour (follows Léa) is in Léa's close friends. */
export function seedCloseFriends(): MockCloseFriend[] {
  return [{ ownerId: USER_IDS.lea, friendId: USER_IDS.nour, at: at(1) }];
}

export const PLAYLIST_IDS = {
  matins: '0192f3a3-0000-7000-8000-000000000001',
  brouillons: '0192f3a3-0000-7000-8000-000000000002',
  lofi: '0192f3a3-0000-7000-8000-000000000003',
} as const;

export function seedPlaylists(): {
  playlists: MockPlaylist[];
  items: Record<string, MockPlaylistItem[]>;
} {
  const T = (n: string) => `0192f3a2-0000-7000-8000-0000000000${n}`;
  return {
    playlists: [
      {
        id: PLAYLIST_IDS.matins,
        name: 'Matins calmes',
        description: 'Pour démarrer la journée en douceur.',
        isPublic: true,
        ownerId: USER_IDS.lea,
        moderation: 'visible',
        createdAt: at(600),
        updatedAt: at(60),
      },
      {
        id: PLAYLIST_IDS.brouillons,
        name: 'Brouillons',
        description: null,
        isPublic: false,
        ownerId: USER_IDS.lea,
        moderation: 'visible',
        createdAt: at(500),
        updatedAt: at(50),
      },
      {
        id: PLAYLIST_IDS.lofi,
        name: 'Lo-fi pour bosser',
        description: 'Concentration garantie.',
        isPublic: true,
        ownerId: USER_IDS.kenji,
        moderation: 'visible',
        createdAt: at(900),
        updatedAt: at(90),
      },
    ],
    items: {
      [PLAYLIST_IDS.matins]: [
        { trackId: T('01'), addedAt: at(300) },
        { trackId: T('07'), addedAt: at(290) },
        { trackId: T('02'), addedAt: at(280) },
        { trackId: T('08'), addedAt: at(270) },
      ],
      [PLAYLIST_IDS.brouillons]: [
        { trackId: T('04'), addedAt: at(200) },
        { trackId: T('0a'), addedAt: at(190) }, // kenji's private track: unavailable to Léa
      ],
      [PLAYLIST_IDS.lofi]: [
        { trackId: T('07'), addedAt: at(400) },
        { trackId: T('08'), addedAt: at(390) },
        { trackId: T('09'), addedAt: at(380) },
      ],
    },
  };
}

export function seedLikes(): { tracks: MockLike[]; playlists: MockLike[] } {
  const T = (n: string) => `0192f3a2-0000-7000-8000-0000000000${n}`;
  return {
    tracks: [
      { userId: USER_IDS.lea, targetId: T('07'), at: at(30) },
      { userId: USER_IDS.lea, targetId: T('09'), at: at(20) },
      { userId: USER_IDS.kenji, targetId: T('03'), at: at(10) },
    ],
    playlists: [{ userId: USER_IDS.lea, targetId: PLAYLIST_IDS.lofi, at: at(15) }],
  };
}
