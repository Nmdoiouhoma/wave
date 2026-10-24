import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll, beforeEach } from 'vitest';
import { server } from '@/mocks/server';
import { mockConfig, resetDb } from '@/mocks/db';
import { sessionStore } from '@/stores/session';

mockConfig.persist = false;

beforeAll(() => server.listen({ onUnhandledRequest: 'error' }));
beforeEach(() => {
  resetDb();
  sessionStore.setState({ status: 'anonymous', accessToken: null, user: null });
});
afterEach(() => {
  cleanup();
  server.resetHandlers();
  server.events.removeAllListeners();
});
afterAll(() => server.close());
