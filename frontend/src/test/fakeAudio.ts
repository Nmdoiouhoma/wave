import type { AudioLike } from '@/features/player/webAudioEngine';

type Listener = () => void;

/** Minimal HTMLAudioElement stand-in (jsdom does not implement media playback). */
export class FakeAudio {
  src = '';
  currentTime = 0;
  duration = Number.NaN;
  paused = true;
  volume = 1;
  muted = false;
  playCalls = 0;
  /** When set, play() rejects with this error. */
  playError: Error | null = null;
  private listeners = new Map<string, Set<Listener>>();

  play(): Promise<void> {
    this.playCalls++;
    if (this.playError) return Promise.reject(this.playError);
    this.paused = false;
    // `playing` fires once media data arrives (see loadMetadata), never for a broken URL.
    return Promise.resolve();
  }
  pause(): void {
    if (this.paused) return;
    this.paused = true;
    this.emit('pause');
  }
  load(): void {
    this.currentTime = 0;
    this.duration = Number.NaN;
  }
  removeAttribute(name: string): void {
    if (name === 'src') this.src = '';
  }
  addEventListener(type: string, fn: Listener): void {
    if (!this.listeners.has(type)) this.listeners.set(type, new Set());
    this.listeners.get(type)!.add(fn);
  }
  removeEventListener(type: string, fn: Listener): void {
    this.listeners.get(type)?.delete(fn);
  }
  emit(type: string): void {
    this.listeners.get(type)?.forEach((fn) => fn());
  }

  /** Simulates the browser loading metadata of the current src. */
  loadMetadata(durationSec: number): void {
    this.duration = durationSec;
    this.emit('loadedmetadata');
    if (!this.paused) this.emit('playing');
  }
  /** Simulates playback progress in 250 ms ticks. */
  advance(ms: number): void {
    for (let t = 0; t < ms; t += 250) {
      this.currentTime += 0.25;
      this.emit('timeupdate');
    }
  }
  /** Simulates a failed (e.g. 403/410) media request. */
  fail(): void {
    this.paused = true;
    this.emit('error');
  }

  asAudio(): AudioLike {
    return this as unknown as AudioLike;
  }
}
