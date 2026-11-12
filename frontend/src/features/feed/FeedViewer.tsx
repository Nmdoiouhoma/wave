import { useCallback, useEffect, useLayoutEffect, useRef, useState, type ReactNode } from 'react';
import { ChevronDownIcon } from '@/components/ui/icons';
import { playerStore } from '@/stores/player';
import { FeedItem } from './FeedItem';
import { feedApi, type Post } from './feedApi';
import { FeedPlayback, type ClipStatus } from './feedPlayback';

const VIEW_TICK_MS = 1_000;
const VIEW_FLUSH_MS = 10_000;
/** Media elements stay mounted this many items around the active one. */
const NEAR = 1;
/** One wheel gesture (a trackpad flick fires dozens of events) moves one post. */
const WHEEL_LOCK_MS = 700;
const WHEEL_THRESHOLD = 20;

/** Keys typed in a field or inside a sheet/dialog are not feed navigation. */
function ownsKeys(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) return false;
  return (
    target.isContentEditable ||
    !!target.closest('input, textarea, select, [role="dialog"], [role="menu"]')
  );
}

function pauseGlobalPlayer() {
  const { status, pause } = playerStore.getState();
  if (status === 'playing' || status === 'loading') pause();
}

/**
 * Full-screen snap-scroll viewer shared by the feeds and `/posts/:id`. The active item plays its
 * clip; `footer` (end card, loader) is one more snap item after the posts.
 */
export function FeedViewer({
  posts,
  header,
  footer,
  onNearEnd,
  initialIndex = 0,
  onActiveChange,
}: {
  posts: Post[];
  header?: ReactNode;
  footer?: ReactNode;
  onNearEnd?: () => void;
  /** Post shown first, without animation (back navigation, FB-24 A5). */
  initialIndex?: number;
  onActiveChange?: (index: number) => void;
}) {
  const scroller = useRef<HTMLDivElement>(null);
  const playback = useRef<FeedPlayback | null>(null);
  const [active, setActive] = useState(() => Math.max(0, Math.min(initialIndex, posts.length - 1)));
  const [clip, setClip] = useState<{ postId: string; status: ClipStatus } | null>(null);
  const count = posts.length + (footer ? 1 : 0);
  const activePost = posts[active] ?? null;
  const nextPost = posts[active + 1] ?? null;

  // Created first: the effects below run after it in the same commit.
  useEffect(() => {
    const p = new FeedPlayback({
      api: feedApi,
      onStatus: (postId, status) => setClip({ postId, status }),
      onClipStart: pauseGlobalPlayer,
    });
    playback.current = p;
    const tick = window.setInterval(() => p.tick(), VIEW_TICK_MS);
    const flush = window.setInterval(() => p.flush(), VIEW_FLUSH_MS);
    const onVisibility = () => (document.visibilityState === 'hidden' ? p.hide() : p.show());
    const onPageHide = () => p.pageHide();
    const onPageShow = () => p.pageShow();
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);
    return () => {
      window.clearInterval(tick);
      window.clearInterval(flush);
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('pagehide', onPageHide);
      window.removeEventListener('pageshow', onPageShow);
      p.dispose();
      playback.current = null;
    };
  }, []);

  useEffect(() => {
    playback.current?.setActive(activePost);
    playback.current?.preload(nextPost);
  }, [activePost, nextPost]);

  useEffect(() => {
    if (onNearEnd && active >= posts.length - 3) onNearEnd();
  }, [active, posts.length, onNearEnd]);

  const go = useCallback(
    (i: number) => {
      const target = Math.max(0, Math.min(count - 1, i));
      setActive(target);
      const el = scroller.current;
      if (el && el.clientHeight > 0 && typeof el.scrollTo === 'function')
        el.scrollTo({ top: target * el.clientHeight, behavior: 'smooth' });
    },
    [count],
  );

  const onScroll = () => {
    const el = scroller.current;
    if (!el || el.clientHeight <= 0) return;
    const i = Math.max(0, Math.min(count - 1, Math.round(el.scrollTop / el.clientHeight)));
    if (i !== active) setActive(i);
  };

  // Reopen on the saved post: jump there before paint (`active` is already set).
  useLayoutEffect(() => {
    const el = scroller.current;
    if (el && el.clientHeight > 0) el.scrollTop = active * el.clientHeight;
    // Mount only: later moves go through `go` or the user's own scroll.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // FB-24 A1: keys work as soon as the feed is on screen, wherever the focus is (a click on a
  // post focuses its play button, not the scroller), except in fields and sheets.
  const activeRef = useRef(active);
  useEffect(() => {
    activeRef.current = active;
    onActiveChange?.(active);
  }, [active, onActiveChange]);
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || ownsKeys(e.target)) return;
      let step = 0;
      if (e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === 'j') step = 1;
      else if (e.key === 'ArrowUp' || e.key === 'PageUp' || e.key === 'k') step = -1;
      if (!step) return;
      e.preventDefault();
      go(activeRef.current + step);
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [go]);

  // FB-24 A1: the wheel moves exactly one post per gesture instead of fighting the snap.
  useEffect(() => {
    const el = scroller.current;
    if (!el) return;
    let lockedUntil = 0;
    const onWheel = (e: WheelEvent) => {
      if (ownsKeys(e.target) || Math.abs(e.deltaY) < Math.abs(e.deltaX)) return;
      e.preventDefault();
      const now = performance.now();
      if (now < lockedUntil || Math.abs(e.deltaY) < WHEEL_THRESHOLD) {
        // Still the same gesture: keep the lock while its inertia lasts.
        if (now < lockedUntil) lockedUntil = now + WHEEL_LOCK_MS;
        return;
      }
      lockedUntil = now + WHEEL_LOCK_MS;
      go(activeRef.current + Math.sign(e.deltaY));
    };
    el.addEventListener('wheel', onWheel, { passive: false });
    return () => el.removeEventListener('wheel', onWheel);
  }, [go]);

  return (
    <div className="relative h-full bg-black">
      {header}
      <div
        ref={scroller}
        role="feed"
        aria-label="Extraits"
        aria-busy={false}
        tabIndex={0}
        onScroll={onScroll}
        className="h-full snap-y snap-mandatory [scrollbar-width:none] overflow-y-scroll overscroll-contain outline-none md:mx-auto md:max-w-[min(100%,calc(100dvh*9/16))]"
      >
        {posts.map((post, i) => (
          <FeedItem
            key={post.id}
            post={post}
            active={i === active}
            near={Math.abs(i - active) <= NEAR}
            status={i === active && clip?.postId === post.id ? clip.status : null}
            onTogglePlay={() => playback.current?.togglePlay()}
          />
        ))}
        {footer && (
          <div className="flex h-full snap-start snap-always items-center justify-center px-6 text-center text-white">
            {footer}
          </div>
        )}
      </div>
      {count > 1 && (
        <div className="absolute top-1/2 right-4 z-20 hidden -translate-y-1/2 flex-col gap-3 md:flex">
          <button
            type="button"
            aria-label="Extrait précédent"
            disabled={active === 0}
            onClick={() => go(active - 1)}
            className="flex size-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 disabled:opacity-30"
          >
            <ChevronDownIcon size={22} className="rotate-180" />
          </button>
          <button
            type="button"
            aria-label="Extrait suivant"
            disabled={active >= count - 1}
            onClick={() => go(active + 1)}
            className="flex size-11 items-center justify-center rounded-full bg-white/10 text-white hover:bg-white/20 disabled:opacity-30"
          >
            <ChevronDownIcon size={22} />
          </button>
        </div>
      )}
    </div>
  );
}
