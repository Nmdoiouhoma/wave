// HTMLAudioElement implementation of PlayerEngine.
// - fetches a signed stream URL on load (D2, TTL 5 min);
// - on media error (403/410 are invisible to <audio>) or an expired URL, fetches a fresh URL and
//   resumes at the same position (ADR 001 §6: one retry, then surface the error);
// - reports one listen per play session (openapi `reportListen`).
import type { StreamUrl, Track } from '@/api/types';
import { errorMessage } from '@/lib/errorMessages';
import type { PlayerEngine, PlayerReport } from '@/stores/player';
import { ListenTracker } from './listenTracker';
import { createMediaSessionBridge, type MediaSessionBridge } from './mediaSession';

export type AudioLike = Pick<
  HTMLAudioElement,
  | 'src'
  | 'currentTime'
  | 'duration'
  | 'paused'
  | 'volume'
  | 'muted'
  | 'play'
  | 'pause'
  | 'load'
  | 'removeAttribute'
  | 'addEventListener'
  | 'removeEventListener'
>;

export interface EngineDeps {
  fetchStreamUrl(trackId: string): Promise<StreamUrl>;
  reportListen(trackId: string, listenedMs: number, keepalive: boolean): Promise<unknown>;
  report(r: PlayerReport): void;
  onEnded(): void;
  commands: { play(): void; pause(): void; next(): void; previous(): void; seek(ms: number): void };
  now?: () => number;
  /** Refresh the URL this long before `expiresAt` when (re)starting playback. */
  expiryMarginMs?: number;
  mediaSession?: MediaSessionBridge | null;
}

const NOOP_SESSION: MediaSessionBridge = {
  setTrack: () => {},
  setPlaying: () => {},
  setPosition: () => {},
  clear: () => {},
};

export class WebAudioEngine implements PlayerEngine {
  private track: Track | null = null;
  private stream: { url: string; expiresAt: number } | null = null;
  /** Bumped on every load/stop to drop stale async results. */
  private generation = 0;
  private retried = false;
  private wantPlaying = false;
  private pendingSeekMs: number | null = null;
  private lastPositionMs = 0;
  private recovering = false;
  private readonly tracker = new ListenTracker();
  private readonly now: () => number;
  private readonly margin: number;
  private readonly session: MediaSessionBridge;
  private readonly detach: () => void;

  constructor(
    private readonly audio: AudioLike,
    private readonly deps: EngineDeps,
  ) {
    this.now = deps.now ?? (() => Date.now());
    this.margin = deps.expiryMarginMs ?? 5_000;
    this.session =
      deps.mediaSession === undefined
        ? (createMediaSessionBridge(deps.commands) ?? NOOP_SESSION)
        : (deps.mediaSession ?? NOOP_SESSION);

    const on = <K extends keyof HTMLMediaElementEventMap>(type: K, fn: () => void) => {
      audio.addEventListener(type, fn);
      return () => audio.removeEventListener(type, fn);
    };
    const offs = [
      on('loadedmetadata', this.handleMetadata),
      on('timeupdate', this.handleTime),
      on('playing', this.handlePlaying),
      on('pause', this.handlePause),
      on('waiting', () => this.deps.report({ buffering: true })),
      on('seeking', () => this.tracker.onDiscontinuity()),
      on('ended', this.handleEnded),
      on('error', this.handleError),
    ];
    this.detach = () => offs.forEach((off) => off());
  }

  destroy(): void {
    this.stop();
    this.detach();
  }

  // ───────────── PlayerEngine ─────────────

  load(track: Track, { autoplay, startMs }: { autoplay: boolean; startMs: number }): void {
    this.endListen(false);
    this.track = track;
    this.stream = null;
    this.retried = false;
    this.wantPlaying = autoplay;
    this.lastPositionMs = startMs;
    this.tracker.start(track.id);
    this.session.setTrack(track);
    void this.attachFreshUrl(++this.generation, startMs, autoplay);
  }

  play(): void {
    if (!this.track) return;
    this.wantPlaying = true;
    if (!this.stream || this.isExpired()) {
      // The next Range request would hit 403/410: get a new URL first.
      void this.attachFreshUrl(this.generation, this.positionMs(), true);
      return;
    }
    this.startPlayback();
  }

  pause(): void {
    this.wantPlaying = false;
    this.audio.pause();
  }

  seek(ms: number): void {
    this.lastPositionMs = ms;
    this.tracker.onDiscontinuity();
    if (this.stream && !this.isExpired()) this.audio.currentTime = ms / 1000;
    else this.pendingSeekMs = ms;
  }

  setVolume(volume: number, muted: boolean): void {
    this.audio.volume = volume;
    this.audio.muted = muted;
  }

  stop(): void {
    this.endListen(false);
    this.generation++;
    this.track = null;
    this.stream = null;
    this.wantPlaying = false;
    this.audio.pause();
    this.audio.removeAttribute('src');
    this.audio.load();
    this.session.clear();
  }

  /** `pagehide`: the play session ends now, sent with `keepalive`. */
  flushOnPageHide(): void {
    this.endListen(true);
  }

  // ───────────── internals ─────────────

  private positionMs(): number {
    const t = this.audio.currentTime;
    return Number.isFinite(t) && t > 0 ? t * 1000 : this.lastPositionMs;
  }

  private isExpired(): boolean {
    return !this.stream || this.now() >= this.stream.expiresAt - this.margin;
  }

  private async attachFreshUrl(gen: number, startMs: number, autoplay: boolean): Promise<void> {
    const track = this.track;
    if (!track) return;
    this.recovering = true;
    this.deps.report({ status: 'loading', buffering: false, error: null });
    let stream: StreamUrl;
    try {
      stream = await this.deps.fetchStreamUrl(track.id);
    } catch (e) {
      if (gen !== this.generation) return;
      this.recovering = false;
      this.deps.report({ status: 'error', error: errorMessage(e) });
      return;
    }
    if (gen !== this.generation) return;
    this.stream = { url: stream.url, expiresAt: Date.parse(stream.expiresAt) };
    this.pendingSeekMs = startMs > 0 ? startMs : null;
    this.tracker.onDiscontinuity();
    this.audio.src = stream.url;
    this.audio.load();
    this.recovering = false;
    if (autoplay) this.startPlayback();
    else this.deps.report({ status: 'paused' });
  }

  private startPlayback(): void {
    const gen = this.generation;
    this.audio.play().catch((e: unknown) => {
      if (gen !== this.generation) return;
      // Autoplay policy: stay paused, the user can press play.
      if (e instanceof DOMException && e.name === 'NotAllowedError') {
        this.wantPlaying = false;
        this.deps.report({ status: 'paused' });
      }
      // Other failures surface through the `error` event.
    });
  }

  private handleMetadata = () => {
    if (this.pendingSeekMs !== null) {
      this.audio.currentTime = this.pendingSeekMs / 1000;
      this.pendingSeekMs = null;
    }
    const d = this.audio.duration;
    if (Number.isFinite(d) && d > 0) this.deps.report({ durationMs: Math.round(d * 1000) });
  };

  private handleTime = () => {
    if (this.recovering) return;
    const pos = this.audio.currentTime * 1000;
    this.lastPositionMs = pos;
    if (!this.audio.paused) this.tracker.onPosition(pos);
    this.deps.report({ positionMs: pos });
    const d = this.audio.duration;
    this.session.setPosition(pos, Number.isFinite(d) ? d * 1000 : (this.track?.durationMs ?? 0));
  };

  private handlePlaying = () => {
    // Playback works again: a later expiry deserves its own retry.
    this.retried = false;
    this.tracker.anchor(this.audio.currentTime * 1000);
    this.deps.report({ status: 'playing', buffering: false, error: null });
    this.session.setPlaying(true);
  };

  private handlePause = () => {
    if (this.recovering || !this.track) return;
    this.tracker.onDiscontinuity();
    this.deps.report({ status: 'paused', buffering: false });
    this.session.setPlaying(false);
  };

  private handleEnded = () => {
    this.endListen(false);
    this.wantPlaying = false;
    this.deps.onEnded();
  };

  private handleError = () => {
    if (!this.track || !this.audio.src) return;
    if (this.retried) {
      this.wantPlaying = false;
      this.deps.report({
        status: 'error',
        buffering: false,
        error: 'Lecture impossible pour le moment.',
      });
      return;
    }
    // Most likely an expired/invalid signed URL (403/410): refetch and resume where we were.
    this.retried = true;
    void this.attachFreshUrl(this.generation, this.lastPositionMs, this.wantPlaying);
  };

  private endListen(keepalive: boolean): void {
    const s = this.tracker.end();
    if (!s || s.listenedMs <= 0) return;
    this.deps.reportListen(s.trackId, s.listenedMs, keepalive).catch(() => {
      /* best effort: a lost listen is not worth bothering the user */
    });
  }
}
