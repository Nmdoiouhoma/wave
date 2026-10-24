// Counts audio time actually played (seeks excluded) for one play session (openapi `reportListen`).
// Pure logic: the web engine feeds it media positions; a React Native engine can do the same.

/** Gaps larger than this between two position updates are treated as seeks, not playback. */
const MAX_TICK_MS = 1_500;

export interface ListenSession {
  trackId: string;
  listenedMs: number;
}

export class ListenTracker {
  private session: (ListenSession & { lastPos: number | null }) | null = null;

  start(trackId: string): void {
    this.session = { trackId, listenedMs: 0, lastPos: null };
  }

  get current(): ListenSession | null {
    return this.session
      ? { trackId: this.session.trackId, listenedMs: this.session.listenedMs }
      : null;
  }

  /** Media position update while playing (ms). */
  onPosition(positionMs: number): void {
    const s = this.session;
    if (!s) return;
    if (s.lastPos !== null) {
      const delta = positionMs - s.lastPos;
      if (delta > 0 && delta <= MAX_TICK_MS) s.listenedMs += delta;
    }
    s.lastPos = positionMs;
  }

  /** Sets the baseline when playback (re)starts, unless one is already set. */
  anchor(positionMs: number): void {
    if (this.session && this.session.lastPos === null) this.session.lastPos = positionMs;
  }

  /** A seek or a source reload breaks continuity: the next position becomes the new baseline. */
  onDiscontinuity(): void {
    if (this.session) this.session.lastPos = null;
  }

  /** Ends the session; returns it once (null when none is open). */
  end(): ListenSession | null {
    const s = this.session;
    this.session = null;
    return s ? { trackId: s.trackId, listenedMs: Math.round(s.listenedMs) } : null;
  }
}
