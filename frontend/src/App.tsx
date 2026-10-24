import { QueryClientProvider } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { RouterProvider } from 'react-router';
import { apiClient } from '@/api/instance';
import { createQueryClient } from '@/api/queryClient';
import { keys } from '@/api/queries/keys';
import { PlayerHost } from '@/features/player/PlayerHost';
import { createAppRouter } from '@/routes/router';
import { playerStore } from '@/stores/player';
import { sessionStore } from '@/stores/session';

/** Session restore: the httpOnly refresh cookie is the only thing that survives a reload. */
function useSessionRestore(queryClient: ReturnType<typeof createQueryClient>) {
  useEffect(() => {
    if (sessionStore.getState().status !== 'unknown') return;
    // Another open tab hands its session over; otherwise one cross-tab-locked refresh.
    apiClient.restoreSession().catch(() => sessionStore.getState().clearSession('expired'));
  }, [queryClient]);

  // Session changes may come from another tab (login, rotation, logout, token reuse).
  useEffect(
    () =>
      sessionStore.subscribe((state, prev) => {
        if (state.user && state.user !== prev.user) {
          if (prev.user && prev.user.id !== state.user.id) queryClient.clear();
          queryClient.setQueryData(keys.me(), state.user);
        }
        if (prev.status === 'authenticated' && state.status === 'anonymous') {
          playerStore.getState().stop();
          queryClient.clear();
        }
      }),
    [queryClient],
  );
}

export function App() {
  const [queryClient] = useState(createQueryClient);
  const [router] = useState(createAppRouter);
  useSessionRestore(queryClient);

  return (
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <PlayerHost />
    </QueryClientProvider>
  );
}
