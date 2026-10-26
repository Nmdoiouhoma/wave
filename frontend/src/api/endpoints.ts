// One function per phase-1 operation of docs/openapi.yaml. UI-agnostic.
import type { ApiClient } from './client';
import type {
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
    },
    users: {
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
