# Wave — Tickets (phases 1–5)

Format: **W-xx · owner · deps** then acceptance criteria (AC). "Done" = ACs pass + PHPStan 8 / ESLint clean + tests in the ticket's scope + qa-security review of the batch. Contract changes during a ticket go to architect via the lead.
Parallel start of phase 1: **media-infra** W-01→W-02, **backend** W-03→W-04 (needs only a local Postgres until W-01 lands), **frontend** W-12 against MSW mocks generated from `openapi.yaml`.

## Phase 1 — Docker, auth, profiles, upload, streaming, global player

**W-01 · media-infra · —** Compose stack + Makefile + `.env.example`
- `make up` starts php, worker, nginx, postgres 16, minio (+ bucket init `wave-private`/`wave-public` with ADR 001 §5 policies), front; only nginx publishes a port.
- php/worker images `FROM php:8.4.27-fpm-bookworm@sha256:…`; worker adds ffmpeg/ffprobe; memory limits and hardening of ADR 006 applied.
- `make audit` fails when PHP < 8.4.27 or security-http < 7.4.13 (test by temporarily pinning an older tag).
- `.env.example` lists every var of CONVENTIONS §9.
- `mailpit` service (SMTP 1025 internal, web UI 8025 published in dev only, absent from prod profile).

**W-02 · media-infra · W-01** nginx + FPM config
- `/api/` → FPM; `/media/` → `wave-public` read-only with `immutable` cache; `/_protected/private/` internal-only (direct request → 404) with slice + proxy cache (ADR 001).
- Body limits: 32m on `POST /api/v1/tracks|posts`, 6m on avatar/cover routes, 1m elsewhere (a 2 MB JSON POST → 413).
- FPM pool settings of ADR 006 verifiable with `php-fpm -tt`.

**W-03 · backend · —** Symfony skeleton and API conventions
- Symfony ≥ 7.4.13, API Platform 4 JSON-only under `/api/v1`, no Hydra; composer constraints of ADR 006.
- `ProblemCode` enum mirrors `openapi.yaml`; any exception renders problem+json; unknown route → 404 `not_found`; prod 500 has no trace.
- `CursorCodec` round-trips and rejects a tampered cursor (400 `invalid_cursor`), unit-tested.
- PHPStan 8 + PHP-CS-Fixer + PHPUnit wired to `make test`.

**W-04 · backend · W-03** Baseline schema (phase 1 tables)
- Migration creates extensions, `wave_unaccent`, `app_user`, `refresh_token`, `artist`, `genre` (seeded list), `track` with constraints, indexes and search triggers of DATA_MODEL.
- `doctrine:schema:validate` passes except the documented GIN/tsvector items; migration runs up/down on an empty DB.

**W-05 · backend · W-04** Auth
- register/login/refresh/logout per contract; web = cookie (`HttpOnly; Secure`(prod)`; SameSite=Strict; Path=/api/v1/auth`), `X-Wave-Client: mobile` = body; refresh rotation (reused token → 401).
- Rate limits of the contract return 429 + `Retry-After`; login error identical for unknown email and wrong password.
- Suspended user → 403 `account_suspended` on login and refresh.

**W-17 · backend · W-05, W-07** Email verification
- `account_token` table + `AccountTokenIssuer`; register dispatches `SendVerificationEmail`; mail visible in Mailpit with a working link.
- `POST /auth/verify-email` sets `emailVerifiedAt`; expired, reused or unknown token → 400 `account_token_invalid`; `POST /me/email-verification` 202, 409 when verified, 429 after 3/h.
- Unverified user gets 403 `email_not_verified` on every `x-requires-verified-email` operation (one functional test per operation, added as those operations land); `Me.emailVerified` reflects state.

**W-18 · backend · W-17** Password reset
- `POST /auth/password-reset` returns 202 with identical body for existing, unknown and suspended emails (test asserts byte-identical responses); mail only for the existing one.
- Confirm sets the password, marks email verified, revokes all refresh tokens (old refresh → 401); token single-use, expires after 1 h.

**W-06 · backend · W-05** Me and profiles
- `GET/PATCH /me`, `POST /me/password` (revokes refresh tokens), `GET /users/{username}` with `isMe`.
- Unknown username → 404; `PATCH /me` ignores/refuses `username`/`email` (422 on unknown property).

**W-07 · backend · W-03** ObjectStorage + Messenger plumbing
- `ObjectStorage` + `FlysystemObjectStorage` (two buckets) + in-memory test double; message classes of CONVENTIONS §8 exist with payloads.
- Transports `media`, `async`, `failed` configured; dispatch inside a transaction is rolled back with it (functional test).

**W-08 · backend · W-06, W-07** Artists
- Artist CRUD by slug, `GET /users/{username}/artists`, avatar upload (202, dispatches `ProcessImage`), limit 10 → 409.
- Slug generation with accents and collisions (`Beyoncé` → `beyonce`, then `beyonce-2`); slug unchanged on rename.
- Delete with tracks → 409 `artist_has_tracks`; non-owner PATCH → 403.

**W-09 · backend · W-08** Tracks
- `POST /tracks` multipart → 202 `processing`, raw stored in quarantine, `ProcessTrack` dispatched; artist resolution rules (auto-create / single / 422 `artist_required`).
- 31 MB file → 413 `payload_too_large`; `.txt` declared type → 415.
- GET/PATCH/DELETE + cover upload + `/users/{u}/tracks`, `/artists/{slug}/tracks` with visibility rules (private/processing/hidden → 404 for others, visible to owner).
- Delete purges keys (`PurgeObjects`) and cascades per DATA_MODEL.

**W-10 · media-infra · W-07 (interfaces only), W-01** Media handlers
- `ProcessTrack`: real MP3/AAC → ready, `.m4a` AAC 160k faststart, integrated loudness −14 ± 1 LUFS (measured in test); renamed `.exe`/PNG → failed `invalid_media`; 11-min file → failed `too_long`.
- `ProcessImage`: output WebP at sizes of DATA_MODEL §14; non-image → dropped, previous key kept.
- `PurgeObjects`, `CleanupQuarantine` idempotent (run twice = no error).
- Handlers idempotent on redelivery (track already ready → no-op).

**W-11 · backend · W-09, W-02** Streaming
- `stream-url` → signed URL TTL 300 s; `/stream/{token}` → X-Accel to `/_protected/private/audio/tracks/{id}.m4a`.
- Tampered token → 403, expired → 410, track made private after issuance → 404 on next request.
- Through nginx: `Range: bytes=0-1023` → 206 with correct `Content-Range`; seeking works in Chrome and Safari.

**W-12 · frontend · —** Front scaffold
- Vite + React 19 + TS strict + Tailwind + Router + TanStack Query + Zustand; `npm run gen:api` generates `schema.d.ts` from `docs/openapi.yaml`.
- MSW handlers for every phase-1 operation with fixtures typed from the schema; app runs fully on mocks (`VITE_MOCKS=1`).
- API client: bearer in memory, single-flight refresh on 401 `token_expired`, problem+json parsed into a typed error.

**W-13 · frontend · W-12** Auth and profile screens
- Register/login/logout, session restore via refresh on load, field errors mapped from `violations`.
- Profile page (`/u/:username`) and edit-profile + avatar upload with 202 pending state; mobile-first layout at 360 px.
- Email flows (W-17, W-18): "verify your email" banner with resend while unverified, gated actions disabled with explanation; `/verify-email` and `/reset-password` pages strip the token from the URL; "forgot password" form shows the same message whatever the answer.

**W-14 · frontend · W-12** Artists and upload
- Upload form (file, title, genre, artist, visibility, allowClips) with client-side size check, progress bar, then polling until ready/failed with readable failure reason.
- Artist page (`/a/:slug`), my artists management (create/edit/avatar/delete with 409 handling), track page.

**W-15 · frontend · W-12** Global player
- Zustand player (queue, play/pause, seek, next/prev, volume) persistent across routes; mini player + full player on mobile.
- Fetches `stream-url` on play; on media error or expired URL refetches and resumes at the same position (tested with MSW + fake timers).
- Media Session API (lock-screen controls); sends `POST /tracks/{id}/listens` once per play session (ready for W-23).

**W-16 · qa-security · W-05, W-09, W-11** Phase 1 security review
- `tests/Security`: password-reset anti-enumeration (body and status identical, rate limit per email and IP), account-token reuse/expiry/brute force, verified-email gate bypass attempts, login/register/refresh rate limits, refresh reuse, cookie flags, JWT `alg` confusion / expired token, private track 404 (not 403), `/_protected/` direct access 404, oversize/forged uploads.
- `docs/SECURITY.md` threat model v1; findings filed to owners.

## Phase 2 — Playlists, follow, likes, listens

**W-20 · backend · W-09** Playlists
- CRUD + items add/remove + `PUT /order` + `/users/{u}/playlists`; private/hidden → 404 for others.
- Order PUT with missing/extra/duplicate id → 409 `playlist_order_mismatch`; positions stay contiguous after remove (test).
- 501st item → 409 `playlist_full`; duplicate → 409; unavailable items returned with `track: null`.

**W-21 · backend · W-06** Follow
- PUT/DELETE follow idempotent; counters exact after 2× PUT and 2× DELETE; self-follow 422.
- Followers/following lists keyset-paginated by follow date.

**W-22 · backend · W-09, W-20** Likes
- Track/playlist like PUT/DELETE idempotent returning `LikeState`; `likedByMe` correct in every Track/Playlist payload without N+1 (query count asserted).
- `/me/likes/tracks|playlists` ordered by like date.

**W-23 · backend · W-09** Listens
- Counting rules of DATA_MODEL §11 unit-tested (29 s on 3-min track → not counted; 31 s → counted; 2nd within window → not counted; `listenedMs` > duration + 2 s → not counted).
- Counted listen increments `playCount` in the same transaction and dispatches `RecomputeAffinity` (deduplicated).

**W-24 · frontend · W-20, W-22** Library
- Library tab: my playlists, liked tracks, liked playlists; create/edit/delete playlist; add-to-playlist from any track menu.
- Drag-and-drop reorder (dnd-kit, touch-friendly) sends full order; optimistic update with rollback on 409.

**W-25 · frontend · W-21** Social profile
- Follow button with optimistic count; followers/following lists with infinite scroll.
- Like buttons on tracks/playlists, optimistic with rollback.

**W-26 · qa-security · W-20..W-23** Phase 2 review
- IDOR tests: mutate another user's playlist (403), read private playlist (404), add someone's private track (404).
- Counter race test: 20 concurrent likes on one track → `likeCount` = 20.

## Phase 3 — Posts, vertical feed, comments, reports

**W-30 · backend · W-09** Posts
- `POST /posts` multipart → 202; D5 rules (others' private or `allowClips=false` → 422 `clips_not_allowed`), range → 422 `clip_out_of_range`, clip 14 s → 422.
- Hashtags extracted (`#Lo_Fi #lofi` → one `lo_fi`, one `lofi`; max 10), `hashtag.postCount` maintained.
- `GET/DELETE /posts/{id}`, `/users/{u}/posts`, `Post.clip` signed URL embedded when ready; playable rule enforced (track turned private → others get 404).

**W-31 · media-infra · W-30, W-10** Clip and visual processing
- `GenerateClip` produces exact duration ± 50 ms with fades; `ProcessPostVisual` image → WebP, video → H.264 ≤ 720p, no audio stream (ffprobe asserts), poster generated.
- Post becomes `ready` only when both outputs exist, whatever the completion order (test both orders).
- 70 s video or 20 MB video → failed / 413 respectively.

**W-32 · backend · W-30** Post interactions and following feed
- Post like, comments list/create/delete (author, post author, moderator), `commentCount` exact.
- `POST /post-views` upsert (max `watchedMs`, sticky `completed`), `viewCount` +1 only on first view.
- `GET /feed/following` keyset by date, playable posts of followed users only.

**W-33 · backend · W-30, W-32** Reports and moderation API
- `POST /reports` with 409 duplicate, 422 own content, 404 invisible target.
- 5th distinct reporter auto-hides post/track/comment/playlist/artist (not user); counters adjusted.
- `/admin/report-targets` queue + per-target reports + decision (`hide`/`dismiss`/`suspend`); non-moderator → 403.

**W-34 · frontend · W-30, W-32** Vertical feed
- Full-screen snap-scroll feed (for-you tab uses `/feed/for-you` once W-40 lands; until then MSW), autoplay of the active clip, preload of next clip, pause on tab hide.
- Visual: image, muted looping video or track cover fallback; tap = pause, double-tap = like.
- Views batched (`/post-views` every 10 s + on exit with keepalive); "use this sound" opens the composer when `track.canClip`.

**W-35 · frontend · W-30** Post composer
- Pick track (own or `canClip`), choose start with a scrubber and duration 15–60 s with live preview of the segment.
- Optional visual with client-side type/size checks; caption with hashtag highlighting; processing state until ready.

**W-36 · frontend · W-32, W-33** Comments and reports
- Comments sheet with infinite scroll, post, delete own; rate-limit 429 shows retry time.
- Report dialog for track/post/comment/playlist/artist/user with reason + details; 409 shown as "already reported".

**W-37 · qa-security · W-30..W-33** Phase 3 review
- Comment XSS payloads rendered inert; caption/hashtag injection; report brigading (same user twice) blocked.
- Hidden content invisible in every list and by direct id for non-owners.

## Phase 4 — Recommendations v1, search, demo seed

**W-40 · backend · W-32** Recommendations v1
- `RecomputeAffinity`, `RecomputeTrending` (scheduled) and `SqlFeedRanker` per ADR 005; weights in one class, unit-tested on a fixture dataset (genre-affine user gets that genre ranked first).
- `/feed/for-you`: no seen post, no own post, ≤ 2 per author per page, non-empty fallback when all seen; p95 < 150 ms on demo seed (EXPLAIN attached to PR).

**W-41 · backend · W-04, W-30** Search and hashtags
- `PostgresSearchProvider` for 5 types; `beyonce` finds `Beyoncé`, `lof` finds `lofi` (prefix), `daft pnk` finds `Daft Punk` (trigram).
- `/search` without type returns 5 sections ≤ 5 items; with type paginates up to 200; private/hidden never returned.
- `GET /hashtags/{name}` and `/hashtags/{name}/posts`.

**W-42 · media-infra · W-10, W-31, W-40** Demo seed
- `make seed` generates audio with FFmpeg synthesis only (no external files): ≥ 30 users, 40 artists, 150 tracks over ≥ 10 genres, 300 posts with clips/visuals, follows, likes, listens, comments.
- Seed is deterministic (fixed random seed) and runs in < 5 min; ends with affinity + trending recomputed.

**W-43 · frontend · W-40, W-41** Discovery UI
- For-you/following tabs, search page with type tabs, debounced input (300 ms), hashtag page, genre chips.
- Empty and error states for every list.

**W-44 · qa-security · W-40, W-41** Phase 4 review
- Search SQL injection / tsquery syntax fuzzing (no 500); query-cost check on 100-char inputs.
- EXPLAIN review of feed and search on seed data.

## Phase 5 — Tests, hardening, moderation UI, README

**W-50 · frontend · W-33** Moderation UI
- `/admin` visible only with `ROLE_MODERATOR`; queue grouped by target with preview, reasons, counts; decision buttons with note; per-target report list.

**W-51 · backend · all** Test coverage pass
- Functional test per operation of `openapi.yaml` (status + schema validation against the spec); line coverage of `src/` ≥ 70 %.

**W-52 · frontend · all** Front test pass
- Vitest for player store, API client refresh, feed view batching, playlist reorder; ESLint/Prettier clean; Lighthouse mobile ≥ 85 performance on feed.

**W-53 · media-infra · W-01, W-02** Prod profile
- `compose.prod.yaml`: TLS-ready nginx, `REFRESH_COOKIE_SECURE=1`, no dev ports, restart policies, healthchecks, Postgres + MinIO backups script.
- `README.md` (French): install, `make` targets, architecture, prod checklist (secrets rotation, versions, audit).

**W-55 · backend · W-33, W-40** Account deletion (GDPR)
- `DELETE /me` with password per DATA_MODEL §13b: wrong password 422; success 204, cookie cleared, refresh token → 401, login → 401.
- After deletion no row references the user (query every table) and counters of surviving rows are exact (followers, likes, comment counts, playlist item counts/positions, hashtag counts); other users' posts on the user's tracks are gone.
- `PurgeObjects` dispatched for every collected key (asserted against the in-memory storage); confirmation email in Mailpit; user with 100 tracks / 500 posts deleted in < 5 s.

**W-56 · frontend · W-55** Delete-account UI
- Settings → "Delete my account": explicit consequences text, password field, typed confirmation (`username`), then logout and redirect to home.

**W-54 · qa-security · all** Final security sign-off
- `make audit` green; full `tests/Security` suite green, including deletion (another user cannot delete my account; no residual personal data in DB or storage); `docs/SECURITY.md` final with residual risks; every open finding closed or accepted by the user.
