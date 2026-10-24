import { QueryClientProvider } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { RouterProvider } from 'react-router';
import { apiClient } from '@/api/instance';
import { createQueryClient } from '@/api/queryClient';
import { keys } from '@/api/queries/keys';
import { PlayerHost } from '@/features/player/PlayerHost';
import { createAppRouter } from '@/routes/router';
import { sessionStore } from '@/stores/session';

/** Session restore: the httpOnly refresh cookie is the only thing that survives a reload. */
function useSessionRestore(queryClient: ReturnType<typeof createQueryClient>) {
  useEffect(() => {
    if (sessionStore.getState().status !== 'unknown') return;
    apiClient
      .refresh()
      .then((ok) => {
        const user = sessionStore.getState().user;
        if (ok && user) queryClient.setQueryData(keys.me(), user);
      })
      .catch(() => sessionStore.getState().clearSession());
  }, [queryClient]);
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
