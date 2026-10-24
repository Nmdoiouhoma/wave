# ADR 001 — Signed stream URL, X-Accel-Redirect, Range

Status: accepted (D2) · 2026-10-24

## Context
`<audio>` cannot send a bearer header, audio must never be public, PHP must not proxy bytes, and seeking needs HTTP Range.

## Decision
1. `GET /api/v1/tracks/{id}/stream-url` (and `/posts/{id}/stream-url`, plus `Post.clip` embedded in post payloads) runs the voter, then returns `{url, expiresAt}` with TTL `STREAM_URL_TTL` = 300 s.
2. Token = `base64url(payload) . "." . base64url(HMAC-SHA256(payload, STREAM_SIGNING_KEY))`, payload JSON `{"v":1,"k":"t"|"c","id":"<trackId|postId>","uid":"<viewerId>","exp":<unix>}`. Compared with `hash_equals`. Key distinct from `APP_SECRET`.
3. `GET|HEAD /api/v1/stream/{token}` (no JWT, no session, no rate limiter: it is hit per Range request): bad signature → 403 `stream_token_invalid`; `exp` past → 410 `stream_token_expired`; then **re-checks** that the media exists, is ready and is still visible to `uid` (one indexed query; makes private/hide/delete effective immediately) else 404. Success: empty 200 with `X-Accel-Redirect: /_protected/private/{audioKey}`.
4. nginx:
   ```nginx
   location ^~ /api/v1/stream/ { fastcgi_pass php:9000; include fastcgi_params; fastcgi_param SCRIPT_FILENAME /app/public/index.php; }
   location /_protected/private/ {
     internal;
     slice 1m;
     proxy_cache audio; proxy_cache_key $uri$slice_range; proxy_cache_valid 200 206 7d;
     proxy_set_header Range $slice_range; proxy_http_version 1.1;
     proxy_pass http://minio:9000/wave-private/;
     proxy_hide_header Cache-Control; proxy_hide_header Set-Cookie; proxy_hide_header x-amz-request-id;
     add_header Cache-Control "private, max-age=300" always; add_header Accept-Ranges bytes always;
   }
   ```
   The cache key is the object key, not the token: one cached copy shared by all listeners.
5. MinIO is never published outside the compose network in prod. Bucket `wave-private` allows anonymous `GetObject` **only** with `aws:SourceIp` = compose subnet (nginx); everything else uses credentials.
6. Client: on `error`/403/410 during playback, fetch a fresh URL and resume at `currentTime` (one retry, then surface the error). Objects are written with `Content-Type: audio/mp4` and `+faststart`, so playback starts after the first slice.

## Consequences
+ Bytes served by nginx with Range and caching; PHP cost = one short request per Range request.
+ Revocation within one request, not after TTL.
− A leaked URL plays for ≤ 5 min (acceptable; it is bound to nothing else).
− `/_protected/` and the cache dir must be covered by qa-security tests (direct access must 404).
