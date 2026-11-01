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
  userPlaylists: (username: string) => ['user', username, 'playlists'] as const,
  userFollows: (username: string, which: 'followers' | 'following') =>
    ['user', username, which] as const,
  playlist: (id: string) => ['playlist', id] as const,
  playlistItems: (id: string) => ['playlist', id, 'items'] as const,
  likedTracks: () => ['me', 'likes', 'tracks'] as const,
  likedPlaylists: () => ['me', 'likes', 'playlists'] as const,
  searchUsers: (q: string) => ['search', 'users', q] as const,
};
