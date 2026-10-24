import { QueryClientProvider } from '@tanstack/react-query';
import { render } from '@testing-library/react';
import type { ReactNode } from 'react';
import { createMemoryRouter, RouterProvider } from 'react-router';
import { api } from '@/api/instance';
import { createQueryClient } from '@/api/queryClient';
import { keys } from '@/api/queries/keys';
import { sessionStore } from '@/stores/session';

export async function loginAs(email: 'lea@wave.local' | 'nour@wave.local' | 'kenji@wave.local') {
  const session = await api.auth.login({ email, password: 'motdepasse123' });
  sessionStore.getState().setSession(session);
  return session;
}

export function renderRoute(
  element: ReactNode,
  { path = '/', url = path }: { path?: string; url?: string } = {},
) {
  const queryClient = createQueryClient();
  queryClient.setDefaultOptions({
    queries: { retry: false, staleTime: Infinity, refetchOnWindowFocus: false },
  });
  const user = sessionStore.getState().user;
  if (user) queryClient.setQueryData(keys.me(), user);
  const router = createMemoryRouter(
    [
      { path, element },
      { path: '*', element: <p>autre page</p> },
    ],
    { initialEntries: [url] },
  );
  const utils = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { ...utils, router, queryClient };
}
