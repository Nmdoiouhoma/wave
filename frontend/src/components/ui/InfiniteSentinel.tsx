import { useEffect, useRef } from 'react';
import { Button } from './Button';

/**
 * Infinite scroll: loads the next page when the sentinel nears the viewport; the visible
 * "Charger plus" button stays as the keyboard / no-IntersectionObserver fallback.
 */
export function InfiniteSentinel({
  hasMore,
  loading,
  onLoadMore,
}: {
  hasMore: boolean;
  loading: boolean;
  onLoadMore: () => void;
}) {
  const ref = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const el = ref.current;
    if (!el || !hasMore || typeof IntersectionObserver === 'undefined') return;
    const io = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting) && !loading) onLoadMore();
      },
      { rootMargin: '300px' },
    );
    io.observe(el);
    return () => io.disconnect();
  }, [hasMore, loading, onLoadMore]);
  if (!hasMore) return null;
  return (
    <div ref={ref} className="mt-3 flex justify-center">
      <Button variant="secondary" size="sm" onClick={onLoadMore} loading={loading}>
        Charger plus
      </Button>
    </div>
  );
}
