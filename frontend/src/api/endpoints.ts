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
  TrackUploadFields,
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
      changePassword: (body: ChangePasswordRequest) =>
        request<undefined>('POST', '/me/password', { body }),
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
      upload: (
        file: Blob,
        fields: TrackUploadFields,
        opts: { onProgress?: (loaded: number, total: number) => void; signal?: AbortSignal } = {},
      ) => {
        const form = new FormData();
        form.append('file', file);
        form.append('title', fields.title);
        form.append('genreId', fields.genreId);
        if (fields.artistId) form.append('artistId', fields.artistId);
        if (fields.visibility) form.append('visibility', fields.visibility);
        if (fields.allowClips !== undefined) form.append('allowClips', String(fields.allowClips));
        return upload<Track>('POST', '/tracks', form, opts);
      },
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
