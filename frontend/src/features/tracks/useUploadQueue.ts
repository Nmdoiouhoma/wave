import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '@/api/instance';
import { isApiError } from '@/api/errors';
import { uploadTrackFile } from '@/api/trackUpload';
import type { Track, Visibility } from '@/api/types';
import { errorMessage } from '@/lib/errorMessages';
import { checkTrackFile, titleFromFilename } from '@/lib/files';

/** TICKETS W-63: at most 3 files in flight. */
export const MAX_PARALLEL_UPLOADS = 3;

export type UploadItemStatus =
  | 'ready' // waiting for the user to press "send"
  | 'invalid' // rejected client-side, never sent
  | 'queued'
  | 'preparing'
  | 'sending'
  | 'creating'
  | 'uploaded' // 202 received: processing is followed by the row itself
  | 'error'
  | 'cancelled';

export interface UploadItem {
  id: string;
  file: File;
  title: string;
  genreId: string;
  status: UploadItemStatus;
  progress: number;
  error: string | null;
  track: Track | null;
}

export interface UploadSettings {
  /** Undefined = let the server resolve it (auto-created artist when the user has none). */
  artistId?: string;
  visibility: Visibility;
  allowClips: boolean;
}

const ACTIVE: UploadItemStatus[] = ['queued', 'preparing', 'sending', 'creating'];
export const isActive = (i: UploadItem) => ACTIVE.includes(i.status);

let seq = 0;

export function useUploadQueue({ onUploaded }: { onUploaded?: (track: Track) => void } = {}) {
  const [items, setItemsState] = useState<UploadItem[]>([]);
  const itemsRef = useRef<UploadItem[]>([]);
  const active = useRef(0);
  const controllers = useRef(new Map<string, AbortController>());
  const settings = useRef<UploadSettings>({ visibility: 'public', allowClips: true });
  const onUploadedRef = useRef(onUploaded);
  useEffect(() => {
    onUploadedRef.current = onUploaded;
  }, [onUploaded]);

  const setItems = useCallback((fn: (prev: UploadItem[]) => UploadItem[]) => {
    itemsRef.current = fn(itemsRef.current);
    setItemsState(itemsRef.current);
  }, []);
  const patch = useCallback(
    (id: string, p: Partial<UploadItem>) =>
      setItems((prev) => prev.map((i) => (i.id === id ? { ...i, ...p } : i))),
    [setItems],
  );

  const addFiles = useCallback(
    (files: Iterable<File>, defaultGenreId = '') => {
      const added: UploadItem[] = [...files].map((file) => {
        const problem = checkTrackFile(file);
        return {
          id: `u${++seq}`,
          file,
          title: titleFromFilename(file.name),
          genreId: defaultGenreId,
          status: problem ? 'invalid' : 'ready',
          progress: 0,
          error: problem,
          track: null,
        };
      });
      setItems((prev) => [...prev, ...added]);
    },
    [setItems],
  );

  const run = useCallback(
    async (item: UploadItem) => {
      const ctrl = new AbortController();
      controllers.current.set(item.id, ctrl);
      const { artistId, visibility, allowClips } = settings.current;
      try {
        const track = await uploadTrackFile(
          api,
          item.file,
          { title: item.title.trim(), genreId: item.genreId, artistId, visibility, allowClips },
          {
            signal: ctrl.signal,
            onPhase: (phase) => patch(item.id, { status: phase }),
            onProgress: (progress) => patch(item.id, { progress }),
          },
        );
        // Without artistId the server auto-creates/selects the artist: reuse it for the others.
        settings.current.artistId ??= track.artist.id;
        patch(item.id, { status: 'uploaded', progress: 1, track });
        onUploadedRef.current?.(track);
      } catch (e) {
        if (isApiError(e) && e.code === 'aborted') patch(item.id, { status: 'cancelled' });
        else patch(item.id, { status: 'error', error: errorMessage(e) });
      } finally {
        controllers.current.delete(item.id);
      }
    },
    [patch],
  );

  const pump = useCallback(
    function pumpQueue() {
      for (;;) {
        // No artist yet: the first upload decides it (auto-created artist), the others wait.
        const limit = settings.current.artistId ? MAX_PARALLEL_UPLOADS : 1;
        if (active.current >= limit) return;
        const next = itemsRef.current.find((i) => i.status === 'queued');
        if (!next) return;
        active.current++;
        patch(next.id, { status: 'preparing', error: null, progress: 0 });
        void run(next).finally(() => {
          active.current--;
          pumpQueue();
        });
      }
    },
    [patch, run],
  );

  const start = useCallback(
    (s: UploadSettings) => {
      settings.current = { ...s };
      setItems((prev) =>
        prev.map((i) => (i.status === 'ready' ? { ...i, status: 'queued', error: null } : i)),
      );
      pump();
    },
    [setItems, pump],
  );

  const retry = useCallback(
    (id: string) => {
      patch(id, { status: 'queued', error: null, progress: 0 });
      pump();
    },
    [patch, pump],
  );

  const cancel = useCallback(
    (id: string) => {
      const ctrl = controllers.current.get(id);
      if (ctrl) ctrl.abort();
      else patch(id, { status: 'cancelled' });
    },
    [patch],
  );

  const remove = useCallback(
    (id: string) => setItems((prev) => prev.filter((i) => i.id !== id)),
    [setItems],
  );

  const update = useCallback(
    (id: string, p: Partial<Pick<UploadItem, 'title' | 'genreId'>>) => patch(id, p),
    [patch],
  );

  const clearFinished = useCallback(
    () => setItems((prev) => prev.filter((i) => i.status !== 'uploaded')),
    [setItems],
  );

  return { items, addFiles, start, retry, cancel, remove, update, clearFinished, setItems };
}
