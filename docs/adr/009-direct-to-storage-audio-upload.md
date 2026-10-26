# ADR 009 — Direct-to-storage audio upload (presigned POST)

Status: accepted (FB-04) · 2026-10-26. Supersedes the multipart `POST /tracks` of ADR 003 for audio.

## Context
MP4 (audio or video) must be accepted up to 200 MB. Pushing 200 MB through nginx → FPM → PHP temp file → storage ties an FPM child for minutes and forces `post_max_size` up for every route.

## Decision
- **One flow for every audio file** (MP3/AAC/M4A/MP4): the multipart route is removed (pre-release, no client to keep). Two code paths with different limits and failure modes would cost more than it saves.
  1. `POST /tracks/uploads` `{filename, size, contentType}` → `track_upload` row + presigned **S3 POST policy** (15 min): exact key `quarantine/uploads/{userId}/{uploadId}`, `content-length-range` [1, maxBytes] (200 MB for `.mp4`, 30 MB otherwise). POST, not PUT: only a POST policy can enforce a size range.
  2. Browser posts the form straight to storage (progress via XHR `upload.onprogress`).
  3. `POST /tracks` `{uploadId, title, genreId, artistId?, visibility?, allowClips?}` → checks owner, `pending`, age ≤ 2 h, object exists (HEAD, real size) → consumes atomically → 202 `processing` → `ProcessTrack`.
- **Never trust declarations**: the worker ffprobes the object: needs an audio stream (MP3/AAC), ≤ 10 min, MP4/MOV container ≤ 200 MB else ≤ 30 MB; `-map 0:a:0 -vn` keeps the first audio stream, video discarded; output unchanged (AAC 160k m4a, −14 LUFS). Temp files on a disk volume (`MEDIA_TMP_DIR`), never tmpfs (1G memory limit).
- **Reachability**. Dev: same origin through nginx — `location = /upload/wave-private { limit_except POST { deny all; } client_max_body_size 201m; proxy_request_buffering off; proxy_pass http://minio:9000/wave-private; }`, every other `/upload/` path → 404 (nginx's IP has anonymous read on the private bucket, ADR 001: a GET through this location would leak audio). `S3_PRESIGN_ENDPOINT=http://localhost:8090/upload`; SigV4 POST signs the policy, not the host, so the proxy path is valid. Prod: `S3_PRESIGN_ENDPOINT` = bucket endpoint; bucket CORS allows `POST` from `FRONT_PUBLIC_URL` only, no other method/origin.
- **Security**: upload bound to user (404 to others), single use (atomic consume), 10 pending max per user, rate limit 30/h and 100/day (sized for album uploads, FB-05), size enforced by the signed policy and again by the worker, key fixed by policy (no path choice), quarantine never served, orphans purged by `CleanupQuarantine` (pending > 2 h → expired + object deleted).

## Consequences
+ PHP/nginx never carry audio bytes; 200 MB uploads cost one row and two short requests.
+ Same contract on web and React Native (plain HTTP form POST).
− Two round-trips per file; storage must be browser-reachable (dev proxy, prod CORS). Covers/avatars/post visuals stay multipart (≤ 15 MB).
