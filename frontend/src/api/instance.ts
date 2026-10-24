import { API_BASE_URL } from '@/lib/config';
import { sessionStore } from '@/stores/session';
import { createApiClient } from './client';
import { createWaveApi } from './endpoints';

export const apiClient = createApiClient({
  baseUrl: API_BASE_URL,
  session: {
    getAccessToken: () => sessionStore.getState().accessToken,
    setSession: (s) => sessionStore.getState().setSession(s),
    clearSession: () => sessionStore.getState().clearSession(),
  },
});

export const api = createWaveApi(apiClient);
