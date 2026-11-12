// W-34: audio of the vertical feed, outside React. One clip plays at a time on the active
// element while the next clip preloads on a standby element (swapped on activation). Also
// accounts views (`/post-views`, batched) and feed listens (`source=feed`, DATA_MODEL §11).
import type { StreamUrl } from '@/api/types';
import { ListenTracker } from '@/features/player/listenTracker';
import type { AudioLike } from '@/features/player/webAudioEngine';
import type { FeedApi, Post, PostViewReport } from './feedApi';

export type ClipStatus = 'loading' | 'playing' | 'paused' | 'blocked' | 'unavailable';

export type ClipAudio = AudioLike & { loop: boolean; preload: string };

const defaultFactory = (): ClipAudio => new Audio() as ClipAudio;
let audioFactory: () => ClipAudio = defaultFactory;

/** Tests swap the HTMLAudioElement for a fake (happy-dom has no media playback). */
export function setClipAudioFactory(factory: (() => ClipAudio) | null): void {
  audioFactory = factory ?? defaultFactory;
}

/** A post counts as viewed once it stayed active this long. */
export const VIEW_THRESHOLD_MS = 1_000;
/** Renew a signed clip URL this close to its expiry instead of letting the media request fail. */
const EXPIRY_MARGIN_MS = 5_000;
const VIEWS_PER_BATCH = 50;
const MAX_WATCHED_MS = 600_000;

export interface FeedPlaybackDeps {
  api: Pick<FeedApi, 'streamUrl' | 'reportViews' | 'reportListen'>;
  onStatus(postId: string, status: ClipStatus): void;
  /** A clip starts: the global player must pause (no auto-resume). */
  onClipStart(): void;
  now?: () => number;
  createAudio?: () => ClipAudio;
}

interface Active {
  post: Post;
  since: number;
  retried: boolean;
}

const noop = () => {};

export class FeedPlayback {
  private active: ClipAudio;
  private standby: ClipAudio;
  private current: Active | null = null;
  private token = 0;
  private readonly urls = new Map<string, StreamUrl>();
  private readonly tracker = new ListenTracker();
  private readonly views = new Map<string, number>();
  private pausedByHide = false;
  private parked: Post | null = null;
  private disposed = false;
  private readonly now: () => number;
  private readonly offs: (() => void)[] = [];

  constructor(private readonly deps: FeedPlaybackDeps) {
    this.now = deps.now ?? (() => Date.now());
    const create = deps.createAudio ?? audioFactory;
    this.active = create();
    this.standby = create();
    for (const el of [this.active, this.standby]) {
      el.loop = true;
      el.preload = 'auto';
      this.listen(el);
    }
  }

  private listen(el: ClipAudio): void {
    const on = (type: string, fn: () => void) => {
      const handler = () => {
        if (el === this.active && this.current && !this.disposed) fn();
      };
      el.addEventListener(type, handler);
      this.offs.push(() => el.removeEventListener(type, handler));
    };
    on('timeupdate', () => this.tracker.onPosition(el.currentTime * 1000));
    on('playing', () => {
      this.tracker.anchor(el.currentTime * 1000);
      this.status('playing');
    });
    on('pause', () => {
      this.tracker.onDiscontinuity();
      this.status('paused');
    });
    on('seeking', () => this.tracker.onDiscontinuity());
    on('error', () => void this.recover());
  }

  private status(s: ClipStatus): void {
    if (this.current) this.deps.onStatus(this.current.post.id, s);
  }

  private clipUrl(post: Post): StreamUrl | null {
    return this.urls.get(post.id) ?? post.clip ?? null;
  }

  private fresh(url: StreamUrl | null): url is StreamUrl {
    return !!url && Date.parse(url.expiresAt) - this.now() > EXPIRY_MARGIN_MS;
  }

  private async refresh(post: Post): Promise<StreamUrl | null> {
    try {
      const url = await this.deps.api.streamUrl(post.id);
      this.urls.set(post.id, url);
      return url;
    } catch {
      return null;
    }
  }

  /** The post now on screen (null: none, e.g. the end card). Leaving a clip reports its listen. */
  setActive(post: Post | null): void {
    if (this.disposed || this.current?.post.id === post?.id) return;
    this.leave(false);
    this.parked = null;
    if (!post) return;
    const token = ++this.token;
    this.current = { post, since: this.now(), retried: false };
    this.pausedByHide = false;
    void this.start(token);
  }

  private async start(token: number): Promise<void> {
    const post = this.current!.post;
    let url = this.clipUrl(post);
    if (!url) return this.status('unavailable');
    this.status('loading');
    if (!this.fresh(url)) {
      url = await this.refresh(post);
      if (token !== this.token || this.disposed) return;
      if (!url) return this.status('unavailable');
    }
    this.load(url.url);
    this.play();
  }

  private load(src: string): void {
    if (this.standby.src === src) {
      [this.active, this.standby] = [this.standby, this.active];
      this.active.currentTime = 0;
    } else {
      this.active.src = src;
    }
    this.tracker.start(this.current!.post.id);
  }

  private play(): void {
    if (!this.current) return;
    const token = this.token;
    this.deps.onClipStart();
    this.active.play().then(
      () => {
        if (token === this.token && !this.active.paused) this.status('playing');
      },
      (e: unknown) => {
        // Autoplay policy: wait for a tap. Other failures surface through the `error` event.
        if (token === this.token && (e as { name?: string })?.name === 'NotAllowedError')
          this.status('blocked');
      },
    );
  }

  /** `/stream` answered 403/410 or the URL expired: one fresh URL per activation, then give up. */
  private async recover(): Promise<void> {
    const cur = this.current;
    if (!cur) return;
    if (cur.retried) return this.status('unavailable');
    cur.retried = true;
    const token = this.token;
    this.status('loading');
    const url = await this.refresh(cur.post);
    if (token !== this.token || this.disposed) return;
    if (!url) return this.status('unavailable');
    this.active.src = url.url;
    this.tracker.onDiscontinuity();
    this.play();
  }

  togglePlay(): void {
    if (!this.current || this.disposed) return;
    this.pausedByHide = false;
    if (this.active.paused) {
      if (this.active.src) this.play();
      else void this.start(this.token);
    } else this.active.pause();
  }

  /** Preloads the clip that comes next (no-op when its URL is missing or about to expire). */
  preload(post: Post | null): void {
    if (this.disposed || !post || post.id === this.current?.post.id) return;
    const url = this.clipUrl(post);
    if (this.fresh(url) && this.standby.src !== url.url) this.standby.src = url.url;
  }

  /** Tab hidden: pause (resumed when it comes back) and send what is pending. */
  hide(): void {
    if (this.current && !this.active.paused) {
      this.pausedByHide = true;
      this.active.pause();
    }
    this.flush(true);
  }

  show(): void {
    if (this.pausedByHide && this.current) {
      this.pausedByHide = false;
      this.play();
    }
  }

  /** Page unload (or bfcache): the clip is left now; `pageShow` takes it back. */
  pageHide(): void {
    const post = this.current?.post ?? null;
    this.leave(true);
    this.flush(true);
    this.parked = post;
  }

  pageShow(): void {
    const post = this.parked;
    this.parked = null;
    if (post) this.setActive(post);
  }

  /** Called every second: the active post counts as viewed once active ≥ 1 s. */
  tick(): void {
    const cur = this.current;
    if (!cur || this.now() - cur.since < VIEW_THRESHOLD_MS) return;
    this.recordView(cur.post, this.tracker.current?.listenedMs ?? 0);
  }

  private recordView(post: Post, watchedMs: number): void {
    if (post.isOwner) return; // ignored by the server anyway
    const ms = Math.round(Math.max(0, Math.min(watchedMs, post.clipDurationMs, MAX_WATCHED_MS)));
    this.views.set(post.id, Math.max(this.views.get(post.id) ?? 0, ms));
  }

  /** Sends pending views (every 10 s, on feed exit and on page hide with keepalive). */
  flush(keepalive = false): void {
    if (!this.views.size) return;
    const all: PostViewReport[] = [...this.views].map(([postId, watchedMs]) => ({
      postId,
      watchedMs,
    }));
    this.views.clear();
    for (let i = 0; i < all.length; i += VIEWS_PER_BATCH) {
      this.deps.api.reportViews(all.slice(i, i + VIEWS_PER_BATCH), { keepalive }).catch(noop);
    }
  }

  private leave(keepalive: boolean): void {
    const cur = this.current;
    if (!cur) return;
    this.tick();
    const session = this.tracker.end();
    this.current = null;
    this.token++;
    this.pausedByHide = false;
    if (!this.active.paused) this.active.pause();
    if (session && session.listenedMs > 0) {
      const ms = Math.min(session.listenedMs, cur.post.clipDurationMs);
      this.deps.api.reportListen(cur.post, ms, { keepalive }).catch(noop);
    }
  }

  dispose(): void {
    if (this.disposed) return;
    this.leave(true);
    this.flush(true);
    this.disposed = true;
    for (const off of this.offs) off();
    for (const el of [this.active, this.standby]) {
      el.pause();
      el.removeAttribute('src');
      el.load();
    }
  }
}
