import { useEffect, useState } from 'react';
import { useLocation, useNavigate } from 'react-router';

/**
 * Reads `?token=` once, then removes it from the address bar (replace, no history entry) so it
 * never lingers in history or leaks through Referer. Also forces `no-referrer` while mounted.
 */
export function useTokenFromUrl(): string | null {
  const location = useLocation();
  const navigate = useNavigate();
  const [token] = useState(() => new URLSearchParams(location.search).get('token'));

  useEffect(() => {
    if (new URLSearchParams(location.search).has('token')) {
      void navigate({ pathname: location.pathname, search: '' }, { replace: true });
    }
  }, [location.pathname, location.search, navigate]);

  useEffect(() => {
    const meta = document.createElement('meta');
    meta.name = 'referrer';
    meta.content = 'no-referrer';
    document.head.appendChild(meta);
    return () => meta.remove();
  }, []);

  return token;
}
