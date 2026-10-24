// Seed data for the MSW mock backend. Shapes mirror DATA_MODEL; API payloads are built by
// mappers typed against the generated schema (see db.ts).
import type { FailureReason, Genre, MediaStatus, Visibility } from '@/api/types';

export type MockAudio = 'tone-a' | 'tone-b' | 'tone-c';

/** Real durations of the generated files in public/mock-audio (scripts/gen-mock-audio.sh). */
export const MOCK_AUDIO_DURATION_MS: Record<MockAudio, number> = {
  'tone-a': 48_000,
  'tone-b': 36_000,
  'tone-c': 60_000,
};

export interface MockUser {
  id: string;
  email: string;
  password: string;
  username: string;
  displayName: string;
  bio: string | null;
  avatarUrl: string | null;
  createdAt: string;
  emailVerified: boolean;
  roles: ('ROLE_USER' | 'ROLE_MODERATOR' | 'ROLE_ADMIN')[];
  suspended: boolean;
  followersCount: number;
  followingCount: number;
}

export interface MockArtist {
  id: string;
  slug: string;
  name: string;
  bio: string | null;
  avatarUrl: string | null;
  ownerId: string;
  moderation: 'visible' | 'hidden';
  createdAt: string;
}

export interface MockTrack {
  id: string;
  title: string;
  artistId: string;
  ownerId: string;
  genreId: string;
  coverUrl: string | null;
  durationMs: number | null;
  status: MediaStatus;
  failureReason: FailureReason;
  visibility: Visibility;
  allowClips: boolean;
  moderation: 'visible' | 'hidden';
  playCount: number;
  likeCount: number;
  createdAt: string;
  audio: MockAudio;
  /** Epoch ms when the fake worker finishes; only while `processing`. */
  processAt?: number;
  /** Outcome applied at `processAt`. */
  processOutcome?: FailureReason;
}

export const GENRES = [
  { id: 'pop', name: 'Pop' },
  { id: 'rock', name: 'Rock' },
  { id: 'hip-hop', name: 'Hip-Hop' },
  { id: 'electronic', name: 'Électronique' },
  { id: 'lofi', name: 'Lo-fi' },
  { id: 'jazz', name: 'Jazz' },
  { id: 'rnb', name: 'R&B' },
  { id: 'folk', name: 'Folk' },
  { id: 'ambient', name: 'Ambient' },
  { id: 'classical', name: 'Classique' },
  { id: 'reggae', name: 'Reggae' },
  { id: 'metal', name: 'Metal' },
] as const satisfies readonly Genre[];

/** Demo accounts (password for all: `motdepasse123`). */
export const DEMO_PASSWORD = 'motdepasse123';

const t = (iso: string) => iso;

export const USER_IDS = {
  lea: '0192f3a0-0000-7000-8000-000000000001',
  kenji: '0192f3a0-0000-7000-8000-000000000002',
  nour: '0192f3a0-0000-7000-8000-000000000003',
} as const;

export function seedUsers(): MockUser[] {
  return [
    {
      id: USER_IDS.lea,
      email: 'lea@wave.local',
      password: DEMO_PASSWORD,
      username: 'lea',
      displayName: 'Léa Martin',
      bio: 'Synthés analogiques et nappes du dimanche matin.',
      avatarUrl: null,
      createdAt: t('2026-09-02T10:12:00Z'),
      emailVerified: true,
      roles: ['ROLE_USER'],
      suspended: false,
      followersCount: 128,
      followingCount: 42,
    },
    {
      id: USER_IDS.kenji,
      email: 'kenji@wave.local',
      password: DEMO_PASSWORD,
      username: 'kenji',
      displayName: 'Kenji Sato',
      bio: 'Beatmaker lo-fi, Lyon.',
      avatarUrl: null,
      createdAt: t('2026-08-14T18:40:00Z'),
      emailVerified: true,
      roles: ['ROLE_USER'],
      suspended: false,
      followersCount: 2310,
      followingCount: 87,
    },
    {
      id: USER_IDS.nour,
      email: 'nour@wave.local',
      password: DEMO_PASSWORD,
      username: 'nour',
      displayName: 'Nour',
      bio: null,
      avatarUrl: null,
      createdAt: t('2026-10-20T09:00:00Z'),
      emailVerified: false,
      roles: ['ROLE_USER'],
      suspended: false,
      followersCount: 3,
      followingCount: 12,
    },
  ];
}

export function seedArtists(): MockArtist[] {
  return [
    {
      id: '0192f3a1-0000-7000-8000-000000000001',
      slug: 'lea-martin',
      name: 'Léa Martin',
      bio: 'Projet solo : ambient, synthés et field recordings.',
      avatarUrl: null,
      ownerId: USER_IDS.lea,
      moderation: 'visible',
      createdAt: t('2026-09-02T10:20:00Z'),
    },
    {
      id: '0192f3a1-0000-7000-8000-000000000002',
      slug: 'nuit-blanche',
      name: 'Nuit Blanche',
      bio: 'Duo électro nocturne.',
      avatarUrl: null,
      ownerId: USER_IDS.lea,
      moderation: 'visible',
      createdAt: t('2026-09-10T21:05:00Z'),
    },
    {
      id: '0192f3a1-0000-7000-8000-000000000003',
      slug: 'kenji-waves',
      name: 'Kenji Waves',
      bio: 'Boucles chaudes, vinyles poussiéreux.',
      avatarUrl: null,
      ownerId: USER_IDS.kenji,
      moderation: 'visible',
      createdAt: t('2026-08-15T08:00:00Z'),
    },
  ];
}

type SeedTrack = Omit<MockTrack, 'durationMs' | 'coverUrl' | 'moderation' | 'likeCount'> &
  Partial<Pick<MockTrack, 'durationMs'>>;

function track(seed: SeedTrack): MockTrack {
  return {
    coverUrl: null,
    moderation: 'visible',
    likeCount: Math.floor(seed.playCount / 7),
    durationMs: seed.status === 'ready' ? MOCK_AUDIO_DURATION_MS[seed.audio] : null,
    ...seed,
  };
}

export function seedTracks(now = Date.now()): MockTrack[] {
  const [leaMartin, nuitBlanche, kenjiWaves] = seedArtists();
  const lm = leaMartin!.id;
  const nb = nuitBlanche!.id;
  const kw = kenjiWaves!.id;
  const base = {
    status: 'ready',
    failureReason: null,
    visibility: 'public',
    allowClips: true,
  } as const;
  return [
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000001',
      title: 'Brume sur le port',
      artistId: lm,
      ownerId: USER_IDS.lea,
      genreId: 'ambient',
      playCount: 1204,
      createdAt: t('2026-10-01T09:00:00Z'),
      audio: 'tone-a',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000002',
      title: 'Marée basse',
      artistId: lm,
      ownerId: USER_IDS.lea,
      genreId: 'ambient',
      playCount: 873,
      createdAt: t('2026-10-05T19:30:00Z'),
      audio: 'tone-b',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000003',
      title: 'Néons',
      artistId: nb,
      ownerId: USER_IDS.lea,
      genreId: 'electronic',
      playCount: 3120,
      createdAt: t('2026-10-10T22:45:00Z'),
      audio: 'tone-c',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000004',
      title: 'Démo privée',
      artistId: nb,
      ownerId: USER_IDS.lea,
      genreId: 'electronic',
      playCount: 4,
      visibility: 'private',
      createdAt: t('2026-10-15T23:10:00Z'),
      audio: 'tone-a',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000005',
      title: 'Session du jeudi',
      artistId: lm,
      ownerId: USER_IDS.lea,
      genreId: 'ambient',
      playCount: 0,
      status: 'failed',
      failureReason: 'too_long',
      createdAt: t('2026-10-18T12:00:00Z'),
      audio: 'tone-b',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000006',
      title: 'Aube (en cours)',
      artistId: lm,
      ownerId: USER_IDS.lea,
      genreId: 'ambient',
      playCount: 0,
      status: 'processing',
      createdAt: new Date(now).toISOString().replace(/\.\d+Z$/, 'Z'),
      audio: 'tone-c',
      processAt: now + 20_000,
      processOutcome: null,
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000007',
      title: 'Café crème',
      artistId: kw,
      ownerId: USER_IDS.kenji,
      genreId: 'lofi',
      playCount: 15420,
      createdAt: t('2026-09-01T07:00:00Z'),
      audio: 'tone-b',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000008',
      title: 'Pluie sur Croix-Rousse',
      artistId: kw,
      ownerId: USER_IDS.kenji,
      genreId: 'lofi',
      playCount: 9870,
      createdAt: t('2026-09-12T07:00:00Z'),
      audio: 'tone-a',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-000000000009',
      title: 'Vinyle 33',
      artistId: kw,
      ownerId: USER_IDS.kenji,
      genreId: 'hip-hop',
      playCount: 6400,
      createdAt: t('2026-09-30T07:00:00Z'),
      audio: 'tone-c',
    }),
    track({
      ...base,
      id: '0192f3a2-0000-7000-8000-00000000000a',
      title: 'Brouillon secret',
      artistId: kw,
      ownerId: USER_IDS.kenji,
      genreId: 'lofi',
      playCount: 0,
      visibility: 'private',
      createdAt: t('2026-10-12T07:00:00Z'),
      audio: 'tone-a',
    }),
  ];
}
