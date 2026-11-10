// One function per phase-1 operation of docs/openapi.yaml. UI-agnostic.
import type { ApiClient } from './client';
import type {
  SearchResults,
  CloseFriendPage,
  CloseFriendState,
  CreatePlaylistRequest,
  FollowState,
  LikeState,
  Playlist,
  PlaylistItem,
  PlaylistItemPage,
  PlaylistPage,
  UpdatePlaylistRequest,
  UserPage,
  Artist,
  AuthSession,
  ChangePasswordRequest,
  CreateArtistRequest,
  Genre,
  ListenRequest,
  ListenResult,
  LoginRequest,
  Me,
  PasswordResetConfirmRequest,
  RegisterRequest,
  StreamUrl,
  Track,
  TrackPage,
  TrackUploadSlot,
  CreateTrackRequest,
  CreateTrackUploadRequest,
  UpdateArtistRequest,
  UpdateMeRequest,
  UpdateTrackRequest,
  UserProfile,
} from './types';

export interface PageParams {
  cursor?: string | null;
  limit?: number;
}

const enc = encodeURIComponent;

function imageForm(file: Blob): FormData {
  const form = new FormData();
  form.append('file', file);
  return form;
}

export function createWaveApi(client: ApiClient) {
  const { request, upload } = client;
  return {
    auth: {
      register: (body: RegisterRequest) =>
        request<AuthSession>('POST', '/auth/register', { body, auth: false }),
      login: (body: LoginRequest) =>
        request<AuthSession>('POST', '/auth/login', { body, auth: false }),
      refresh: () => client.refresh(),
      logout: () => request<undefined>('POST', '/auth/logout', { auth: false }),
      verifyEmail: (token: string) =>
        request<undefined>('POST', '/auth/verify-email', { body: { token }, auth: false }),
      requestPasswordReset: (email: string) =>
        request<undefined>('POST', '/auth/password-reset', { body: { email }, auth: false }),
      confirmPasswordReset: (body: PasswordResetConfirmRequest) =>
        request<undefined>('POST', '/auth/password-reset/confirm', { body, auth: false }),
    },
    me: {
      get: () => request<Me>('GET', '/me'),
      update: (body: UpdateMeRequest) => request<Me>('PATCH', '/me', { body }),
      resendVerification: () => request<undefined>('POST', '/me/email-verification'),
      // ADR 008: returns a rotated session for this device (other sessions are revoked).
      changePassword: (body: ChangePasswordRequest) =>
        request<AuthSession>('POST', '/me/password', { body }),
      uploadAvatar: (file: Blob) => upload<Me>('POST', '/me/avatar', imageForm(file)),
      deleteAvatar: () => request<undefined>('DELETE', '/me/avatar'),
      likedTracks: (page: PageParams = {}) =>
        request<TrackPage>('GET', '/me/likes/tracks', { query: { ...page } }),
      likedPlaylists: (page: PageParams = {}) =>
        request<PlaylistPage>('GET', '/me/likes/playlists', { query: { ...page } }),
      // FB-18, ADR 012: the caller's single close-friends list.
      closeFriends: (scope: 'members' | 'followers', page: PageParams = {}) =>
        request<CloseFriendPage>('GET', '/me/close-friends', { query: { scope, ...page } }),
      addCloseFriend: (username: string) =>
        request<CloseFriendState>('PUT', `/me/close-friends/${enc(username)}`),
      removeCloseFriend: (username: string) =>
        request<CloseFriendState>('DELETE', `/me/close-friends/${enc(username)}`),
    },
    playlists: {
      create: (body: CreatePlaylistRequest) => request<Playlist>('POST', '/playlists', { body }),
      get: (id: string) => request<Playlist>('GET', `/playlists/${enc(id)}`),
      update: (id: string, body: UpdatePlaylistRequest) =>
        request<Playlist>('PATCH', `/playlists/${enc(id)}`, { body }),
      remove: (id: string) => request<undefined>('DELETE', `/playlists/${enc(id)}`),
      items: (id: string, page: PageParams = {}) =>
        request<PlaylistItemPage>('GET', `/playlists/${enc(id)}/items`, { query: { ...page } }),
      addItem: (id: string, trackId: string) =>
        request<PlaylistItem>('POST', `/playlists/${enc(id)}/items`, { body: { trackId } }),
      removeItem: (id: string, trackId: string) =>
        request<undefined>('DELETE', `/playlists/${enc(id)}/items/${enc(trackId)}`),
      reorder: (id: string, trackIds: string[]) =>
        request<undefined>('PUT', `/playlists/${enc(id)}/order`, { body: { trackIds } }),
      like: (id: string) => request<LikeState>('PUT', `/playlists/${enc(id)}/like`),
      unlike: (id: string) => request<LikeState>('DELETE', `/playlists/${enc(id)}/like`),
    },
    users: {
      playlists: (username: string, page: PageParams = {}) =>
        request<PlaylistPage>('GET', `/users/${enc(username)}/playlists`, { query: { ...page } }),
      followers: (username: string, page: PageParams = {}) =>
        request<UserPage>('GET', `/users/${enc(username)}/followers`, { query: { ...page } }),
      following: (username: string, page: PageParams = {}) =>
        request<UserPage>('GET', `/users/${enc(username)}/following`, { query: { ...page } }),
      follow: (username: string) => request<FollowState>('PUT', `/users/${enc(username)}/follow`),
      unfollow: (username: string) =>
        request<FollowState>('DELETE', `/users/${enc(username)}/follow`),
      get: (username: string) => request<UserProfile>('GET', `/users/${enc(username)}`),
      tracks: (username: string, page: PageParams = {}) =>
        request<TrackPage>('GET', `/users/${enc(username)}/tracks`, { query: { ...page } }),
      artists: (username: string) => request<Artist[]>('GET', `/users/${enc(username)}/artists`),
    },
    artists: {
      create: (body: CreateArtistRequest) => request<Artist>('POST', '/artists', { body }),
      get: (slug: string) => request<Artist>('GET', `/artists/${enc(slug)}`),
      update: (slug: string, body: UpdateArtistRequest) =>
        request<Artist>('PATCH', `/artists/${enc(slug)}`, { body }),
      remove: (slug: string) => request<undefined>('DELETE', `/artists/${enc(slug)}`),
      uploadAvatar: (slug: string, file: Blob) =>
        upload<Artist>('POST', `/artists/${enc(slug)}/avatar`, imageForm(file)),
      deleteAvatar: (slug: string) => request<undefined>('DELETE', `/artists/${enc(slug)}/avatar`),
      tracks: (slug: string, page: PageParams = {}) =>
        request<TrackPage>('GET', `/artists/${enc(slug)}/tracks`, { query: { ...page } }),
    },
    search: {
      /** Phase 2: `type=users` (W-27) and `type=tracks` (W-29a). */
      users: (q: string, page: PageParams = {}, opts: { signal?: AbortSignal } = {}) =>
        request<SearchResults>('GET', '/search', {
          query: { q, type: 'users', ...page },
          signal: opts.signal,
        }).then((r): UserPage => r.users ?? { items: [], nextCursor: null }),
      /** W-29a: own tracks then liked ones first (backend ranking, no client merge). */
      tracks: (q: string, page: PageParams = {}, opts: { signal?: AbortSignal } = {}) =>
        request<SearchResults>('GET', '/search', {
          query: { q, type: 'tracks', ...page },
          signal: opts.signal,
        }).then((r): TrackPage => r.tracks ?? { items: [], nextCursor: null }),
    },
    genres: {
      list: () => request<Genre[]>('GET', '/genres', { auth: false }),
    },
    tracks: {
      // ADR 009 — step 1: upload slot (presigned POST policy).
      createUpload: (body: CreateTrackUploadRequest) =>
        request<TrackUploadSlot>('POST', '/tracks/uploads', { body }),
      // Step 2: the browser posts the file straight to storage.
      sendToStorage: (
        slot: TrackUploadSlot,
        file: Blob,
        opts: { onProgress?: (loaded: number, total: number) => void; signal?: AbortSignal } = {},
      ) => client.postToStorage(slot.url, slot.fields, file, opts),
      // Step 3: create the track from the completed upload.
      create: (body: CreateTrackRequest) => request<Track>('POST', '/tracks', { body }),
      get: (id: string) => request<Track>('GET', `/tracks/${enc(id)}`),
      update: (id: string, body: UpdateTrackRequest) =>
        request<Track>('PATCH', `/tracks/${enc(id)}`, { body }),
      remove: (id: string) => request<undefined>('DELETE', `/tracks/${enc(id)}`),
      uploadCover: (id: string, file: Blob) =>
        upload<Track>('POST', `/tracks/${enc(id)}/cover`, imageForm(file)),
      deleteCover: (id: string) => request<undefined>('DELETE', `/tracks/${enc(id)}/cover`),
      like: (id: string) => request<LikeState>('PUT', `/tracks/${enc(id)}/like`),
      unlike: (id: string) => request<LikeState>('DELETE', `/tracks/${enc(id)}/like`),
      streamUrl: (id: string) => request<StreamUrl>('GET', `/tracks/${enc(id)}/stream-url`),
      reportListen: (id: string, body: ListenRequest, opts: { keepalive?: boolean } = {}) =>
        request<ListenResult>('POST', `/tracks/${enc(id)}/listens`, {
          body,
          keepalive: opts.keepalive,
        }),
    },
  };
}

export type WaveApi = ReturnType<typeof createWaveApi>;
