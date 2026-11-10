import { Navigate, useLocation } from 'react-router';

/** `/search?q=` → `/discover?q=` (W-38), query string kept. */
export function SearchRedirect() {
  const { search } = useLocation();
  return <Navigate to={`/discover${search}`} replace />;
}
