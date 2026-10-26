import { http, HttpResponse } from 'msw';
import { describe, expect, it } from 'vitest';
import { db } from '@/mocks/db';
import { server } from '@/mocks/server';
import { loginAs } from '@/test/utils';
import type { ApiError } from './errors';
import { api } from './instance';
import { uploadTrackFile, type UploadPhase } from './trackUpload';

const LEA_MARTIN = '0192f3a1-0000-7000-8000-000000000001';

function file(name: string, type: string, size = 4096) {
  return new File([new Uint8Array(size)], name, { type });
}

function requestLog() {
  const log: string[] = [];
  server.events.on('request:start', ({ request }) => {
    const u = new URL(request.url);
    log.push(`${request.method} ${u.pathname}`);
  });
  return log;
}

describe('ADR 009 upload flow', () => {
  it('slot → presigned POST to storage (no bearer) → POST /tracks', async () => {
    await loginAs('lea@wave.local');
    const log = requestLog();
    let storageAuth: string | null = 'unset';
    server.events.on('request:start', ({ request }) => {
      if (request.url.includes('/mock-storage/'))
        storageAuth = request.headers.get('Authorization');
    });
    const phases: UploadPhase[] = [];
    const progress: number[] = [];
    const track = await uploadTrackFile(
      api,
      file('clip-video.mp4', 'video/mp4', 50_000),
      { title: 'Live', genreId: 'pop', artistId: LEA_MARTIN },
      { onPhase: (p) => phases.push(p), onProgress: (f) => progress.push(f) },
    );
    expect(log).toEqual([
      'POST /api/v1/tracks/uploads',
      'POST /mock-storage/wave-private',
      'POST /api/v1/tracks',
    ]);
    expect(storageAuth).toBeNull();
    expect(phases).toEqual(['preparing', 'sending', 'creating']);
    expect(progress.at(-1)).toBe(1);
    expect(track).toMatchObject({ title: 'Live', status: 'processing' });
    expect(Object.values(db.state.uploads)[0]).toMatchObject({
      status: 'consumed',
      contentType: 'video/mp4',
      maxBytes: 209_715_200,
    });
  });

  it('retries once with a new slot when the upload expired', async () => {
    await loginAs('lea@wave.local');
    let creates = 0;
    server.use(
      http.post('*/api/v1/tracks', () => {
        creates++;
        if (creates === 1)
          return HttpResponse.json(
            {
              type: 'urn:wave:problem:upload_expired',
              title: 'x',
              status: 409,
              code: 'upload_expired',
            },
            { status: 409, headers: { 'Content-Type': 'application/problem+json' } },
          );
        return undefined; // fall through to the default handler
      }),
    );
    const log = requestLog();
    const track = await uploadTrackFile(api, file('a.mp3', 'audio/mpeg'), {
      title: 'A',
      genreId: 'pop',
      artistId: LEA_MARTIN,
    });
    expect(track.title).toBe('A');
    expect(log.filter((l) => l.endsWith('/tracks/uploads'))).toHaveLength(2);
  });

  it('rejects oversize files before any request', async () => {
    await loginAs('lea@wave.local');
    const log = requestLog();
    const big = file('a.mp3', 'audio/mpeg');
    Object.defineProperty(big, 'size', { value: 31_457_281 });
    const err = (await uploadTrackFile(api, big, { title: 'A', genreId: 'pop' }).catch(
      (e: unknown) => e,
    )) as ApiError;
    expect(err.detail).toBe('Fichier trop lourd : 30 Mo maximum pour un MP3 ou AAC/M4A.');
    expect(log).toEqual([]);
  });

  it('server-side limits and pending cap', async () => {
    await loginAs('lea@wave.local');
    const tooBig = (await api.tracks
      .createUpload({ filename: 'a.mp3', size: 31_457_281, contentType: 'audio/mpeg' })
      .catch((e: unknown) => e)) as ApiError;
    expect(tooBig.code).toBe('payload_too_large');
    for (let i = 0; i < 10; i++)
      await api.tracks.createUpload({ filename: `${i}.mp3`, size: 10, contentType: 'audio/mpeg' });
    const capped = (await api.tracks
      .createUpload({ filename: 'x.mp3', size: 10, contentType: 'audio/mpeg' })
      .catch((e: unknown) => e)) as ApiError;
    expect(capped.code).toBe('too_many_pending_uploads');
  });

  it('create fails with upload_incomplete when nothing reached storage', async () => {
    await loginAs('lea@wave.local');
    const slot = await api.tracks.createUpload({
      filename: 'a.mp3',
      size: 10,
      contentType: 'audio/mpeg',
    });
    const e = (await api.tracks
      .create({ uploadId: slot.uploadId, title: 'A', genreId: 'pop', artistId: LEA_MARTIN })
      .catch((x: unknown) => x)) as ApiError;
    expect(e.code).toBe('upload_incomplete');
  });
});
