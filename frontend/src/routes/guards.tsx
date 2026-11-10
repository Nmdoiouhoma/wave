import type { ReactNode } from 'react';
import { Navigate, useLocation } from 'react-router';
import { useSession } from '@/stores/session';
import { WaveLogo } from '@/components/ui/icons';
import { safeNext } from '@/features/auth/safeNext';

export function Splash() {
  return (
    <div
      className="flex min-h-dvh items-center justify-center"
      role="status"
      aria-label="Chargement de Wave"
    >
      <span className="bg-wave flex size-14 animate-pulse items-center justify-center rounded-2xl text-white">
        <WaveLogo size={30} />
      </span>
    </div>
  );
}

export function RequireAuth({ children }: { children: ReactNode }) {
  const status = useSession((s) => s.status);
  const location = useLocation();
  if (status === 'unknown') return <Splash />;
  if (status === 'anonymous') {
    const next = location.pathname + location.search;
    return (
      <Navigate to={next === '/' ? '/login' : `/login?next=${encodeURIComponent(next)}`} replace />
    );
  }
  return children;
}

export function GuestOnly({ children }: { children: ReactNode }) {
  const status = useSession((s) => s.status);
  const location = useLocation();
  if (status === 'unknown') return <Splash />;
  if (status === 'authenticated') {
    // Already signed in: go to `next` when it is a same-app path, otherwise to Pour toi.
    const next = new URLSearchParams(location.search).get('next');
    return <Navigate to={safeNext(next)} replace />;
  }
  return children;
}
