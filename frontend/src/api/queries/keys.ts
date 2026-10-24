// Query keys: [domain, ...params] (CONVENTIONS §3).
export const keys = {
  me: () => ['me'] as const,
  user: (username: string) => ['user', username] as const,
  userTracks: (username: string) => ['user', username, 'tracks'] as const,
  userArtists: (username: string) => ['user', username, 'artists'] as const,
  artist: (slug: string) => ['artist', slug] as const,
  artistTracks: (slug: string) => ['artist', slug, 'tracks'] as const,
  track: (id: string) => ['track', id] as const,
  genres: () => ['genres'] as const,
};
