import { API_BASE_URL } from '@/lib/config';
import { sessionSnapshot, sessionStore } from '@/stores/session';
import { createApiClient } from './client';
import { createTabSync, defaultTabSyncOptions } from './tabSync';
import { createWaveApi } from './endpoints';

export const apiClient = createApiClient({
  baseUrl: API_BASE_URL,
  session: {
    getAccessToken: () => sessionStore.getState().accessToken,
    getSession: () => sessionSnapshot(sessionStore.getState()),
    setSession: (s) => sessionStore.getState().setSession(s),
    clearSession: (reason) => sessionStore.getState().clearSession(reason),
  },
  tabSync: createTabSync(defaultTabSyncOptions()),
});

export const api = createWaveApi(apiClient);
