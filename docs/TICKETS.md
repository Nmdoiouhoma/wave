# Wave — Tickets (phases 1–5)

Format: **W-xx · owner · deps** then acceptance criteria (AC). "Done" = ACs pass + PHPStan 8 / ESLint clean + tests in the ticket's scope + qa-security review of the batch. Contract changes during a ticket go to architect via the lead.
Parallel start of phase 1: **media-infra** W-01→W-02, **backend** W-03→W-04 (needs only a local Postgres until W-01 lands), **frontend** W-12 against MSW mocks generated from `openapi.yaml`.

## Phase 1 — Docker, auth, profiles, upload, streaming, global player

**W-01 · media-infra · —** Compose stack + Makefile + `.env.example`
- `make up` starts php, worker, nginx, postgres 16, minio (+ bucket init `wave-private`/`wave-public` with ADR 001 §5 policies), front; only nginx publishes a port.
- php/worker images `FROM php:8.4.27-fpm-bookworm@sha256:…`; worker adds ffmpeg/ffprobe; memory limits and hardening of ADR 006 applied.
- `make audit` fails when PHP < 8.4.27 or security-http < 7.4.13 (test by temporarily pinning an older tag).
- `.env.example` lists every var of CONVENTIONS §9.
- `mailpit` service (SMTP 1025 internal, web UI on host 8027 in dev only, absent from prod profile).

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
- register/login/refresh/logout per contract; web = cookie (`HttpOnly; Secure`(prod)`; SameSite=Strict; Path=/api/v1/auth`), `X-Wave-Client: mobile` = body; refresh rotation with families: reused token → whole family revoked + 401 `token_reused` (ADR 008).
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

## Feedback batch (before phase 2) — FB-02..FB-05 (`docs/FEEDBACK.md`)

**W-60 · backend · W-09, W-07** Presigned audio upload (FB-04, ADR 009)
- `track_upload` table + `POST /tracks/uploads` (201 slot with POST policy: exact key, `content-length-range`, 15 min) and JSON `POST /tracks` consuming `uploadId`; multipart `POST /tracks` removed.
- `.mp4` declared 200 MB → 201; `.mp3` declared 31 MB → 413; `.wav` → 415; 11th pending → 409 `too_many_pending_uploads`.
- Another user's uploadId → 404; second consume → 409 `upload_already_used`; object missing → 409 `upload_incomplete`; > 2 h → 409 `upload_expired`; two concurrent consumes → exactly one 202.
- `ObjectStorage::presignedPost` implemented on `S3_PRESIGN_ENDPOINT`; functional test uploads through the returned form against MinIO.

**W-61 · media-infra · W-60** Upload path and MP4 processing (FB-04)
- nginx `location = /upload/wave-private` POST-only, 201m, no request buffering; GET/HEAD/other paths under `/upload/` → 404 (test that a known private audio key is **not** readable there).
- `ProcessTrack` accepts MP4/MOV with or without video: output audio-only m4a (ffprobe shows no video stream); MP4 without audio → failed `no_audio`; 40 MB MP3 renamed `.mp4` → failed `too_large`; 11-min MP4 → failed `too_long`.
- Worker temp on a disk volume (`MEDIA_TMP_DIR`), 200 MB file processed within the 1G memory limit; `CleanupQuarantine` expires pending uploads > 2 h and deletes their objects.
- `.env.example` gains `S3_PRESIGN_ENDPOINT`, `MEDIA_TMP_DIR`, `VITE_MAILPIT_URL`; nginx `POST /api/v1/tracks` body limit back to 1m.

**W-62 · frontend · W-60** Upload flow v2 (FB-04)
- File picker accepts `.mp3,.m4a,.aac,.mp4` (`audio/*,video/mp4`); client limits 200 MB for MP4, 30 MB otherwise, clear message before any request.
- Flow slot → form POST to `url` with `fields` then `file` (XHR progress, cancel) → `POST /tracks`; handles 409 codes (retry by requesting a new slot) and polling up to 10 min.
- MSW handlers for both steps (mock storage endpoint).

**W-63 · frontend · W-62** Add tracks to an artist (FB-05) — contract already sufficient (`POST /artists`, then upload with `artistId`)
- Artist creation wizard: step 1 artist form, step 2 multi-file drop zone (optional, skippable) uploading N files with max 3 in parallel, each with its own title (default = filename without extension), genre and progress row.
- Artist page (owner only): "Ajouter des morceaux" opens the same multi-file uploader with that artist preselected; per-file failure does not cancel the others.
- 429 from `track_upload` shown with retry time; unverified email → gated as elsewhere.

**W-64 · frontend · W-13** Mailpit hint in dev (FB-02)
- When `VITE_MAILPIT_URL` is set (dev only), the "verify your email" banner and the forgot-password confirmation show a link to it; production build contains no Mailpit reference (grep the bundle).

**W-65 · backend · W-17, W-18** French transactional emails (FB-03)
- Subjects and bodies (HTML + text) of verify, reset and account-deleted emails in French via `translations/emails.fr.yaml`; snapshot test per template; links unchanged.

**W-66 · qa-security · W-60, W-61** Feedback batch review
- Presigned policy cannot be reused for another key, exceeded in size, or used after expiry; `/upload/` exposes no read path; uploadId IDOR and replay tests; MP4 with malicious metadata/huge stream count does not crash or hang the worker (timeout).

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

**W-27 · backend · W-04, W-21** User search (FB-07)
- `GET /search?type=users&q=` per contract: `beyonce` finds display name `Beyoncé`, `JEAN` finds `jean.dupont`, `jeam` finds `jean` (trigram), `q` of 1 char → 422, other/missing `type` → 422 until phase 4.
- Ranking order asserted on a fixture (exact username > prefix > similarity > followersCount); suspended users and the caller never returned; `isFollowedByMe` correct without N+1.
- Implemented behind `SearchProvider` (`PostgresSearchProvider`, users only) with offset cursor capped at 200; uses the trigram indexes of DATA_MODEL §2 (EXPLAIN in PR shows index use).

**W-28 · frontend · W-27, W-25** User search screen
- "Rechercher" entry in the nav (bottom bar on mobile) → search page; input debounced 300 ms, no request below 2 chars, previous request cancelled.
- Results with avatar, names, follower count and follow/unfollow button (optimistic, shared cache with profile); infinite scroll; empty, error and 429 states.

**W-29a · backend · W-27, W-22** Track search (FB-15, moved forward from phase 4)
- `GET /search?type=tracks&q=` per contract, in `PostgresSearchProvider` next to users (same `q` checks, offset cursor ≤ 200 bound to `q` and `type`, bucket `search`, F10 LIKE escaping); `type` other than `users`/`tracks` → 422.
- Visibility (fixture with one track per case): others' private, processing, failed, hidden tracks, own processing/failed/hidden tracks and tracks of a suspended owner never returned, even on exact title; others' public ready track and own private ready track returned.
- Matching: `beyonce` finds an artist `Beyoncé`, `nuit` finds `La nuit` (per-word prefix), `mideight` finds `Midnight` (trigram). Ranking asserted: own > liked > exact title > prefix > similarity > `playCount` > id; `likedByMe`, `isOwner`, `canClip` correct without N+1 (query count asserted).

**W-29b · frontend · W-29a, W-24** Add tracks from the playlist page (FB-15)
- Playlist page (owner only): "Ajouter des morceaux" opens a sheet; below 2 chars it shows "Tes morceaux" (`/users/{me}/tracks`, ready only) then "Tes titres likés" (`/me/likes/tracks`); from 2 chars, `/search?type=tracks` results (debounced 300 ms, previous request cancelled, infinite scroll), the backend already ranks own and liked first (no client merge).
- Each row: add button → `POST /playlists/{id}/items`; tracks already in the playlist shown as "Ajouté" (409 duplicate treated the same); `playlist_full` and 429 in French; playlist and item counts refreshed in the shared cache.

**W-26 · qa-security · W-20..W-23, W-27** Phase 2 review
- IDOR tests: mutate another user's playlist (403), read private playlist (404), add someone's private track (404).
- User search: no email or suspended account ever returned; `search` rate limits (60/min, 1000/day) enforced; cursor beyond 200 results rejected; tsquery/LIKE metacharacters (`%`, `_`, `:*`, `&`, `'`) do not error or widen matches.
- Counter race test: 20 concurrent likes on one track → `likeCount` = 20.

## Phase 3 — Posts, vertical feed, comments, reports

Lots (lead order corrected for dependencies, see `docs/handoff/phase3-architect.md`; FB-16 added W-45/W-46, `docs/handoff/fb-1109-architect.md`): **1** W-38 ∥ W-30 · **1b** W-47 ∥ W-48 (FB-18 audiences, lot `vis-1110`, ADR 012) · **2** W-31 ∥ W-32 ∥ W-39 · **3** W-33 ∥ W-34 ∥ W-35 ∥ W-45 · **4** W-36 ∥ W-46 then W-37.
Phase-3 visibility rules live in DATA_MODEL §6 (track audience, FB-18) and §9-§13 (post visible/playable — viewer-dependent since FB-18 —, comment visible, report check order); every "invisible → 404" below must be byte-identical (body + headers) to an unknown id (T7, T17).

**W-38 · frontend · —** Navigation shell (FB-11)
- Mobile first (< 768 px): fixed bottom tab bar, 5 entries in this order — Pour toi (`/`), Découvrir (`/discover`), + (Créer, opens a sheet, not a route), Bibliothèque (`/library`), Profil (`/u/{me}`); height 56 px + `env(safe-area-inset-bottom)`, visible labels, targets ≥ 44 px, `nav aria-label="Navigation principale"`, `aria-current="page"` on the active tab.
- Desktop (≥ 768 px, `md`): same 5 entries in the left rail (+ = button "Créer" opening the same menu as a popover), no bottom bar; "Paramètres" stays reachable from Profil; the rail's "Mes artistes" link moves to the own profile header.
- Route map (table-driven Vitest: each path → expected active tab):

  | Path | Screen | Active tab | Filled by |
  |---|---|---|---|
  | `/` | Pour toi feed (stub "Le fil arrive bientôt" until W-34) | Pour toi | W-34 |
  | `/following` | Abonnements feed (stub) | Pour toi | W-34 |
  | `/posts/:id` | single post viewer (stub) | none | W-34 |
  | `/discover` | Découvrir: current user search (SearchPage moved) | Découvrir | W-38 (W-43 extends) |
  | `/search?q=` | redirect to `/discover?q=` (query kept) | Découvrir | W-38 |
  | `/a/:slug`, `/t/:id`, `/u/:other*` | artist, track, other profile | Découvrir | existing |
  | `/library`, `/p/:id` | library, playlist | Bibliothèque | existing |
  | `/u/:me*`, `/settings`, `/me/artists*` | own profile, settings, my artists | Profil | existing |
  | `/posts/new?trackId=` | post composer (stub) | + | W-35 |
  | `/upload` | track import | + | existing |
- The + sheet lists "Publier un extrait" → `/posts/new` and "Importer des morceaux" → `/upload`; Escape/backdrop closes it; focus returns to +.
- `HomePage` removed (its tracks/artists already show on the own profile); after login and on `/login` while authenticated, redirect to `/` (keeps `safeNext`).
- Player: the mini player sits directly above the tab bar (mobile) or at the bottom right of the rail (desktop); the full player opens as a sheet that never covers the tab bar (tapping a tab closes it); on `/`, `/following` and `/posts/:id` the mini player is hidden and the page fills the viewport minus the tab bar (the feed owns audio, W-34). Page content gets bottom padding so nothing hides under bar + mini player.
- Tests: tab order and labels, active-tab table, + sheet links and focus return, `/search?q=ab` → `/discover?q=ab`, full player open → tab bar still in the DOM and clickable, mini player absent on `/`.

**W-30 · backend · W-09** Posts
- One migration creates every phase-3 table of DATA_MODEL §9-§13 (`post`, `hashtag`, `post_hashtag`, `post_like`, `comment`, `post_view` with its `(user_id, last_seen_at, post_id)` index, `report`) plus `notification` and `notification_actor` of §8b (CHECK on target columns, hand-written partial unique index `NULLS NOT DISTINCT`), constraints and indexes; up/down on an empty DB.
- `POST /posts` multipart → 202 `processing`, dispatches `GenerateClip` (+ `ProcessPostVisual` if visual); verified email required (403 `email_not_verified`); rate limit 30/day → 429 with `Retry-After`; unknown multipart field → 422.
- Error order of DATA_MODEL §9: others' private / processing / hidden track and unknown id → identical 404; others' public track with `allowClips=false` → 422 `clips_not_allowed`; own processing track → 409 `track_not_ready`; start + duration > track → 422 `clip_out_of_range`; duration 14 999 or 60 001 → 422; image > 5 MB or video > 15 MB → 413, GIF or MP3 as visual → 415 (content sniffed, not extension only); no post row left on 413/415.
- Hashtags: `#Lo_Fi #lofi #LOFI` → `lo_fi`, `lofi` (NFKC lowercase, deduped), 11 tags → first 10 kept; `hashtag.postCount` exact after create and delete.
- `GET /posts/{id}`, `/users/{u}/posts`: playable / visible rules of DATA_MODEL §9 (track turned private, `allowClips` turned off, track or post hidden, author suspended → others get 404 and the post leaves lists; the author still sees it unless the track is invisible to them); `isOwner`, `likedByMe`; `clip` non-null only when ready and visible, and its issuance writes the listen grant.
- `GET /posts/{id}/stream-url` (shared `stream_url` bucket, 409 `track_not_ready` when processing); `/stream/{token}` serves `k=c` tokens from `audio/clips/{postId}.m4a` and re-checks post visibility for `uid` on every request (post hidden after issuance → 404).
- `DELETE /posts/{id}`: author whatever the visibility, moderator; others: invisible → 404, visible → 403; cleanup of DATA_MODEL §9 (hashtag counts, reports on post and comments, `PurgeObjects` for clip/visual/poster). Track deletion and `DELETE /me` paths run the same cleanup for posts on the track (test: delete a track used by another user's post → that post gone, hashtag counts exact).

**W-47 · backend · W-30, W-21** Track audiences and close friends (FB-18, ADR 012)
- **New** migration (never edit `Version20261110000100`): `chk_track_visibility` → 4 values; table `close_friend` per DATA_MODEL §8 (PK, CHECK owner≠friend, FKs to `app_user` CASCADE, composite FK `(friend_id, owner_id) → follow(follower_id, followed_id) ON DELETE CASCADE`, index `(friend_id, owner_id)`); up/down on an empty DB and on the dev DB; SQL shown to the lead before applying. `Visibility` enum + `CloseFriend` entity.
- One audience rule in one place: a `TrackAudience` service exposing the SQL fragment of DATA_MODEL §6 (uncorrelated `IN`, bound `:v`) and a PHP check for a loaded track (`Track::isVisibleTo` / `Post::isVisibleTo` cannot query: replace their callers, `StreamController` included). Every current copy of `visibility = 'public'` used as "visible to the viewer" goes through it: `TrackRepository`, `PlaylistRepository` (items + `coverKeys`), `LikeRepository`, `PostRepository`, `PostgresSearchProvider`, `TrackVoter`/`TrackLookup`, stream-url providers. `artist.track_count` and `idx_track_trending` stay public-only.
- Post playable per DATA_MODEL §9 (viewer-dependent) in `PostRepository`, `PostProvider`, `/users/{u}/posts`, clip issuance, `/stream` `k=c`; create rule: others' `followers`/`close_friends` track visible to the caller → 422 `clips_not_allowed`, not visible → 404.
- `TrackView.visibility`: real value for owner and moderators, else `public` or null (also inside `Post.track`, `PostMapper`).
- `/me/close-friends` (both scopes, keyset, cursor bound to scope, suspended omitted), `PUT`/`DELETE /me/close-friends/{username}` per contract: non-follower and self → 422 `not_a_follower`; FK violation 23503 from a concurrent unfollow → same 422; unknown or suspended → 404 on PUT, unknown only on DELETE; `social_write` bucket.
- Tests (functional, one matrix test per route family): owner O, follower F, close friend C, stranger S, ex-follower X (followed then unfollowed), removed close friend R, moderator M × visibility {public, followers, close_friends, private}: `GET /tracks/{id}`, stream-url, like, listens, playlist add, post create, report, `/users/{O}/tracks`, `/artists/{slug}/tracks`, search, another user's playlist containing the track (item absent, cover not taken from it), `/me/likes/tracks` after unfollow; each 404 byte-identical (body + headers) to a random uuid. Posts: O's post on a `followers` track playable for F and O, 404 for S; switching the track to `private` → 404 for F at the next request, clip token issued before → 404 at the next `/stream` request; back to `followers` → playable again. Unfollow removes the `close_friend` row (FK); re-follow does not restore it. Track token issued to F then unfollow → next `/stream` request 404. Concurrency: 2 real processes, PUT close friend vs unfollow → never a member without a follow row.
- Perf: `EXPLAIN` of the search and `/users/{u}/tracks` queries with the fragment shows hashed SubPlans (no per-row subquery loop) on a 10 k tracks / 1 k follows fixture; pasted in the handoff.

**W-48 · frontend · W-47, W-38** Audience selector and close friends
- Track form (upload step and track settings): 4-choice selector "Public / Mes abonnés / Amis proches / Moi seul" (radio group, keyboard and screen-reader labelled), hint under "Mes abonnés": "Toute personne qui s'abonne à toi pourra l'écouter"; "Amis proches" shows "Gérer mes amis proches" and the member count; "extraits" option still shown only for Public (existing rule).
- Changing the audience of a track that has own posts: inline note "Tes extraits de ce morceau suivront cette audience" (no confirm dialog).
- Close friends screen (`/settings/close-friends`, reachable from the selector and from Paramètres): followers list (`scope=followers`) with a toggle per row, infinite scroll, optimistic toggle with rollback; 422 `not_a_follower` → "Cette personne ne te suit plus" and the row is removed; empty state "Personne ne te suit encore".
- Display: own tracks show an audience badge (lock / people / star icon + text) on profile, artist and track pages; others' tracks with `visibility: null` show "Partagé avec toi"; types regenerated from `openapi.yaml` (`visibility` nullable, 4 values).
- Post composer (W-35 when it lands, else the existing stub): for an own non-public track, a warning above Publier: "Visible seulement par <audience>" (Tes abonnés / Tes amis proches / Toi seul), and "Si tu changes l'audience du morceau, l'extrait suivra".
- Tests (Vitest + MSW): selector sends each value on create and PATCH; badge per value and `null`; toggle optimistic + rollback on 422; composer warning text per audience and absent for public.

**W-31 · media-infra · W-30, W-10** Clip and visual processing
- `GenerateClip` produces exact duration ± 50 ms with 300 ms fades, from the processed track audio; `ProcessPostVisual` image → WebP within 1080×1920, video → H.264 ≤ 720×1280 ≤ 30 fps, no audio stream (ffprobe asserts), WebP poster generated.
- Post becomes `ready` only when both outputs exist, whatever the completion order (test both orders); a failure of either sets `failed` + reason and the other handler then skips; handlers are idempotent on re-delivery and skip a deleted post; a handler that wrote its key and then finds the post deleted purges that key (phase3-v1 QA bas 3). Clips are produced for every post whatever the track's audience (access is decided at read time, FB-18).
- 70 s video → `failed`/`too_long`; video without video stream or disguised file → `invalid_media`; 20 MB video → 413 at the API (W-30); hardening of T11/T12 applies to visuals (whitelists, timeouts, 50 Mpx cap); quarantine object deleted on success.

**W-32 · backend · W-30, W-47** Post interactions, comments, views, following feed
- `PUT/DELETE /posts/{id}/like` with the F14 rules (unlike of own like whatever the visibility; without a like, invisible → 404); `likeCount` exact under concurrency (`ON CONFLICT`).
- Comments: list (newest first, keyset), create (verified email, 10/min + 200/day → 429), delete; visibility, 403/404 and counters of DATA_MODEL §10; whitespace-only body → 422; `canDelete` true for comment author, post author, moderator; comments of suspended authors absent from the list.
- `POST /post-views`: upsert of DATA_MODEL §11 (max `watchedMs` clamped, sticky `completed` at ≥ 80 %), `viewCount` +1 only on first view; own, unknown and invisible ids ignored with 204; 51 items → 422; 60/min → 429.
- Feed listens: `POST /tracks/{id}/listens` with `source=feed` + `postId` per DATA_MODEL §11 (post of that track **playable for the caller**, viewer-dependent per §9 — not the track visibility alone, phase3-v1 QA moyen 3 —, else 422; counted only after the clip URL was issued); `postId` with `source=player` → 422. Test: O's post on a `followers` track → counted for follower F, 404/422 for stranger S, 422 for F after unfollow.
- Likes, comments and views use "playable for the caller" (FB-18): a stranger cannot like/comment/view O's post on a `followers` or `close_friends` track (404 / ignored), a follower outside close friends neither for `close_friends`.
- `GET /feed/following`: keyset by `(createdAt, id)`, posts of followed, non-suspended users playable for the caller (a `close_friends` post of a followed author appears only for members); follows nobody → `{items: [], nextCursor: null}`; tampered cursor → 400.

**W-39 · backend · W-30, W-47** Pour toi v1 (FB-12, ADR 011)
- `SimpleFeedRanker implements FeedRanker`, bound by DI alias; weights only in `App\Feed\ForYouWeights` (`FOLLOW 0.35`, `GENRE 0.25`, `POPULAR_RECENT 0.25`, `FRESH 0.10`, `JITTER 0.05`, `HALF_LIFE_H 72`, `GENRE_WINDOW_D 90`, `POPULARITY_CAP 1000`); `GET /feed/for-you` provider hydrates ids in ranker order with `Post` payloads (clip embedded).
- Score, phases, author cap, cursor payload and errors exactly as ADR 011; `limit` default 10, max 20 (21 or 0 → 400 `malformed_request`, as `PageRequest`); cursor with another `r`, tampered or with an unknown phase → 400 `invalid_cursor`.
- Fixture tests (each a functional test on a fresh DB): followed author's fresh post ranks above an equally fresh, equally liked unfollowed one; a post in the viewer's most-liked genre ranks above a same-age post of another genre; a 100-like post of today ranks above a 0-like post of today; cold-start viewer (no follows, no likes) gets a non-empty page ordered by freshness ± jitter; same seed → same order.
- Exclusions: own posts, posts with a `post_view` row, posts not playable for the viewer (processing, hidden, others' clip of a non-public track, `allowClips` off, author suspended, author's own track outside the viewer's audience — FB-18 `followers`/`close_friends`/`private`) never appear in phase `u` nor `s`; the candidate query uses the shared audience fragment of W-47 (hashed SubPlans), not a per-row EXISTS written by hand; ≤ 2 posts per author on every page, including a page that crosses from `u` to `s`.
- Fallback: when every candidate is seen, the first page is non-empty (least recently seen first); within one session no post id appears twice even if views are reported between pages; `nextCursor: null` once both phases are exhausted; only own posts in the DB → empty page, `nextCursor: null`.
- Performance: 5 000 posts / 200 users fixture (20 % of posts on restricted tracks, viewer following 50 users and in 10 close-friends lists), each page < 100 ms locally; `EXPLAIN ANALYZE` of both phase queries pasted in the handoff.

**W-33 · backend · W-30, W-32** Reports and moderation API
- `POST /reports`: check order of DATA_MODEL §13 (422 body → 404 invisible → 422 `cannot_report_own` → 409 `already_reported`), verified email, 20/h → 429; for each of the 6 target types an invisible target (hidden, private, non-playable post, comment of an invisible post, suspended user) returns the same bytes as a random uuid.
- 5th distinct reporter auto-hides post/track/comment/playlist/artist (not user), under the advisory lock: 6 concurrent reporters (real processes, as in `ConcurrencySecurityTest`) → target hidden once, `commentCount` / artist `trackCount` moved once; the 201 body is identical before and after auto-hide.
- `/admin/report-targets` queue (order and filters of the contract, preview labels truncated to 140), per-target reports, decision: allowed actions and 422 cases of DATA_MODEL §13, unknown target → 404, conditional moderation updates (hide twice → counters move once); `suspend` revokes every refresh token and the suspended user's next request with a still-valid access JWT → 403 `account_suspended`; non-moderator → 403 on every `/admin` route, before any lookup (unknown target included).

**W-34 · frontend · W-38, W-39, W-32** Vertical feed
- `/` (for-you, `/feed/for-you`) and `/following` (`/feed/following`) with a top switch "Pour toi | Abonnements"; `/posts/:id` shows one post in the same viewer (404 → "Ce post n'est plus disponible"). Real API, MSW only in Vitest.
- Full-screen snap-scroll, autoplay of the active clip, preload of the next clip, pause on tab hide; starting a clip pauses the global player (no auto-resume); a clip URL refreshed through `/posts/{id}/stream-url` when expired or on 403/410.
- Pages deduped by id; short page with a cursor → fetch next immediately; 400 `invalid_cursor` → restart without cursor; `nextCursor: null` → end card "Tu as tout vu" with "Recommencer" (new session); empty first page → empty state linking to Découvrir.
- Visual: image, muted looping video (poster first) or track cover fallback; caption with hashtags as plain text; tap = pause, double-tap = like (optimistic, rollback on error); author → profile, track → track page.
- Views: a post counts once it was active ≥ 1 s, `watchedMs` = audio time played; batched `/post-views` every 10 s, on feed exit and on page hide (`keepalive`); feed listen sent on leaving a clip (`source=feed`, `postId`) per DATA_MODEL §11.
- "Utiliser ce son" opens `/posts/new?trackId=…` only when `track.canClip`; comments and report entry points are placeholders until W-36.

**W-35 · frontend · W-38, W-30, W-31** Post composer
- `/posts/new`: unverified email → the existing verify gate; track picker = own ready tracks (`/users/{me}/tracks`), or the `trackId` query when its `canClip` is true (else message, no form). Own non-public track: keep the audience warning added by W-48 (FB-18).
- Start scrubber + duration 15–60 s with live preview of the segment through the track stream URL; client checks (visual type jpg/png/webp/mp4, image ≤ 5 MB, video ≤ 15 MB and ≤ 60 s read from video metadata) before upload; caption ≤ 300 with counter and hashtag highlighting (text only, no HTML).
- Submit multipart; then poll `GET /posts/{id}` every 2 s until `ready` (→ open `/posts/:id`) or `failed` (reason in French via `errorMessages`), stop after 2 min with "Toujours en cours" + link to the profile; 404/409/413/415/422/429 mapped to French messages.

**W-36 · frontend · W-34, W-32, W-33** Comments and reports
- Comments sheet from the feed and `/posts/:id`: infinite scroll, post, delete when `canDelete`, optimistic `commentCount`; bodies rendered as text; 429 shows the wait from `Retry-After`.
- Report dialog for track/post/comment/playlist/artist/user (track page, playlist page, artist page, profile, feed menu, comment menu), hidden on own content (`isOwner`, `isMe`); 8 reasons in French + optional details ≤ 1000; 201 → "Merci, on regarde", 409 → "Tu as déjà signalé ce contenu", 404 → "Ce contenu n'est plus disponible".

**W-45 · backend · W-30, W-32, W-21, W-22** Notifications (FB-16)
- Event writes of DATA_MODEL §8b in the transaction of follow, track/playlist/post like and comment creation, only when a row was inserted: PUT like twice → one actor; like of own track, comment on own post → no row; follow → `follow` item with `target: null`.
- Aggregation: 3 users like one track → one unread item, `actorCount` 3, `actors` newest first; after `POST /me/notifications/read` a 4th like opens a new item; 10 concurrent likes on one target (real processes) → one unread row, 10 actors; two follows of the same user → one `follow` row (`NULLS NOT DISTINCT` inference).
- Undo and cleanup: unlike/unfollow removes the actor, item deleted when empty; deleting the track, playlist or post (incl. track deletion cascading posts) removes its items; `DELETE /me` of the only actor leaves no item (DATA_MODEL §13b).
- Read filters: suspended actor absent from `actors` and `actorCount`, item with only suspended actors absent from list and count; `post_like` on a post whose clip track turned private (other owner) absent for its author; own hidden track still listed; own `close_friends`/`followers` track and post always listed for their owner (FB-18: the recipient is the owner/author, always in the audience).
- Endpoints per contract: keyset on `(updatedAt, id)` with tampered cursor → 400, `limit` 51 → 400; `unread-count` capped at 100 and equal to the unread items listed; `read` marks only rows with `updatedAt ≤ upTo` and of the caller (another user's unread items untouched), unknown field → 422, bucket `notifications` 120/min → 429.

**W-46 · frontend · W-38, W-45** Notification bell
- Bell in the shell (mobile: top right of the Pour toi/Profil headers, outside the tab bar; desktop: rail) with unread badge (`99+` from 100); count polled every 60 s while the page is visible and on focus, no polling when hidden.
- `/notifications` page (active tab: none): infinite scroll, French sentences per type ("Alice a commencé à te suivre", "Alice et 2 autres ont aimé ton morceau « X »", "… ont commenté ton post"), avatars, relative date, unread items highlighted; tap → profile (`follow`), `/t/:id`, `/p/:id` or `/posts/:id`.
- Opening the page sends `read` with the newest displayed `updatedAt`, badge reset optimistically; labels rendered as text (no HTML); empty and error states.

**W-37 · qa-security · W-30..W-33, W-39, W-45** Phase 3 review
- Oracles (FB-18 included: stranger, ex-follower and removed close friend against `followers`/`close_friends` tracks and their posts): for posts, comments, post likes, post stream-url, `/stream` clip tokens, reports (6 types) and post-views, an invisible target gives the same status, body and headers as an unknown uuid (T7, T17); post creation on another user's private track → 404, not 422.
- Hidden and suspended: hidden posts/comments and suspended authors' posts absent from `/feed/for-you`, `/feed/following`, `/users/{u}/posts`, comment lists, and 404 by id for non-owners; a clip URL issued before hiding stops working (T9).
- Injection and encodings: comment/caption XSS payloads inert in the UI (T15), hashtag extraction with RTL/zero-width/NUL input (T21), unknown multipart and JSON fields (T8), hostile visuals (T11, T12).
- Abuse: report brigading (same user twice → 409, 5 sock puppets → hide then moderator `dismiss` restores), auto-hide race and counters (T18), view and feed-listen inflation (T20: first view only, grant required), rate limits of every new bucket, `/admin` as non-moderator.
- Notifications (W-45): no route takes a notification or user id (nothing to probe); items never show a suspended actor or a target invisible to the recipient (T7, T17); like/unlike and follow/unfollow loops create at most one unread item per read; `read` cannot touch another user's rows.
- `docs/SECURITY.md` extended with phase-3 threats (T22+) and findings.

## Admin back-office (before phase 4) — FB-28, ADR 013

Contract: ADR 013, DATA_MODEL §15-17 (+ §1 enums, §2 `last_login_at`, §13, §13b), `openapi.yaml` `/admin/*` + `/announcements`. Absorbs W-50. Frontend tickets start after the FB-27 redesign is merged (use its components; no styling decided here).
Lots: **1** W-70 · **2** W-71 ∥ W-72 · **3** W-73 ∥ W-74 · **4** W-75 · **5** W-76 ∥ W-77 · then W-79 (user test); W-78 (log server) independent, after the first deploy.

**W-70 · backend · W-33** Journal foundation, roles and console
- One migration: `activity_event`, `security_alert` (generated `subject_key` + partial unique index written by hand, never in a diff), `announcement`, `app_user.last_login_at`; SQL shown to the lead before applying.
- `ActivityJournal::record()` per DATA_MODEL §15: admin events in the caller's transaction, others after commit and never failing the request; IP → `ip_prefix` + HMAC `ip_hash` (`ACTIVITY_IP_PEPPER`, new env var, CONVENTIONS §9, boot fails if empty in prod); UA truncated 200; metadata whitelist (a test asserts no email/token/password in any recorded row).
- Hook the non-admin event types listed in §15 (login success/failure, logout, token reuse, password/email flows, registration, content created/deleted, report created/auto-hidden, upload rejected, rate-limited ≤ 1/min per bucket); `last_login_at` on login.
- `app:admin:grant <email>` / `app:admin:revoke <email>`: journaled with actor NULL; revoke of the last admin refused; unknown email → non-zero exit, no output revealing whether the email exists beyond "not found".
- `PurgeActivityEvents` daily (90 d / admin 365 d / acknowledged alerts 180 d) in batches; §13b journal rules are implemented by W-55 (acceptance bullet added there).
- Role guard on the W-33 `suspend` decision (moderator → moderator/admin, anyone → admin = 403, after 404) and `admin.report_decided` journaled.

**W-71 · backend · W-70** Users and content admin API
- `/admin/dashboard`, `/admin/users`, `/admin/users/{userId}`, `…/suspension` (POST/DELETE), `…/role`, `/admin/content`, `/admin/content/{type}/{id}/deletion`: orders, filters and error order of the contract; moderator gets `email` null and `alerts`/`announcements` null; ROLE_ADMIN routes → 403 for a moderator before any lookup (unknown id included).
- Self-suspension 422, self role change 403, `ROLE_ADMIN` in body 422, admin target 403; role change effective at the target's next request (test with a still-valid JWT).
- Each mutation writes exactly one `admin.*` row with actor, subject, target and note (asserted); content deletion reuses the owner delete processors (counters, `PurgeObjects`, reports) and `artist_has_tracks` → 409.

**W-72 · backend · W-70** Journal, alerts and announcements API
- `/admin/users/{userId}/activity` (first page journals `admin.journal_viewed`), `/admin/audit-log`, `/admin/alerts` + acknowledgement (409 `already_acknowledged`), `/admin/announcements` CRUD, `GET /announcements` (≤ 3 active, `warning` first).
- `EvaluateSecurityRules` async handler with the 8 rules and thresholds of DATA_MODEL §16 as constants; one test per rule (threshold − 1 → no alert, threshold → one alert, further hits → `hitCount` grows, no second open row); dedup holds under 2 concurrent workers.
- Index use checked with EXPLAIN on 100 k events for the journal page and each rule query (attached to the handoff).

**W-73 · frontend · W-71, FB-27** Back-office shell, users and dashboard
- `/admin` route guarded on `me.roles` (ROLE_MODERATOR), lazy-loaded chunk not downloaded by other users; menu entries by role (admin-only pages hidden for moderators; a 403 from the API still shows "Accès réservé").
- Dashboard cards; users list with search, role/status filters and cursor paging; user detail with counts, last login, last suspension, suspend/unsuspend and role change (note required, confirmation dialog), buttons hidden when the guard forbids them.

**W-74 · frontend · W-71, W-33, FB-27** Moderation (absorbs W-50)
- Reports queue grouped by target (preview, reasons, counts), per-target report list, decision buttons with note; content list per type with filters, hide/restore via the decision endpoint, delete (admin only, typed confirmation).
- 403/404/409/422 mapped to French messages; optimistic updates rolled back on error.

**W-75 · frontend · W-72, FB-27** Journal, alerts and announcements
- User journal tab (category filter, IP prefix and UA shown, never more), audit log page, alerts list (severity/status/rule filters) with acknowledgement note, announcements CRUD with date pickers.
- App-wide banner from `GET /announcements` (text only, dismiss stored per `id`+`updatedAt` in local storage, wrapped in try/catch).

**W-76 · media-infra · W-70** Prod wiring of the journal
- `ACTIVITY_IP_PEPPER` in the prod secrets and `.env` docs; scheduler runs the purge; `TRUSTED_PROXIES` checked so `getClientIp()` is the real client (test request through nginx with a forged `X-Forwarded-For` from outside → ignored).
- README prod checklist: create the first admin with `app:admin:grant`, retention periods, privacy notice line for the journal.

**W-77 · qa-security · W-71, W-72** Back-office security review (Opus)
- Every `/admin` route: anonymous 401, user 403, moderator 403 on admin-only routes, before lookup (no existence oracle); IDOR on ids; privilege escalation (moderator → admin, admin → admin, self) impossible; mass assignment on role/announcement bodies.
- Journal privacy: no full IP, email, token or user text in `activity_event`; retention job deletes what it should; account deletion leaves no non-admin event about the user. Announcement body rendered as text (XSS attempt).

**W-78 · media-infra · W-53, first deploy** Technical log server (later)
- Per ADR 013 §9 and the user's choice: second small instance with Loki (filesystem, 14-day retention) + Grafana behind SSH tunnel, agent (Alloy or Promtail) on the app box capped at 96 MB collecting container, nginx and PHP logs; or the Dozzle fallback.
- No business data and no request bodies shipped; nginx access logs with IPs truncated before shipping; memory of the app box measured before/after (handoff).

**W-79 · qa · W-73..W-75** Back-office test with the user
- Browser test prompt for the user (moderator account and admin account), then fixes; closes FB-28.

## Phase 4 — Recommendations v1, search, demo seed, catalogue, repost, admin charts

Contract (lot `p4-contract`): ADR 014 (Deezer catalogue, FB-06), ADR 015 (repost, FB-33), DATA_MODEL §9b + §18, `openapi.yaml` `/catalog/*`, `/discover`, `/posts/{postId}/repost`, `/users/{username}/reposts`, `/admin/dashboard/series`, `Post.repost*`/`canRepost`, `Me.features`, `NotificationType.repost`.
**Launch order** (one backend agent at a time: PHPUnit drops the test database; front and media-infra run beside it):
1. W-82 backend ∥ W-85 media-infra · 2. W-84 backend ∥ W-83 front · 3. W-80 backend ∥ W-86 front · 4. W-40 backend ∥ W-81 front · 5. W-41 backend ∥ W-42 media-infra · 6. W-87 backend · 7. W-43 front · 8. W-44 qa, then user test.

**W-80 · backend · W-32, W-45** Repost (ADR 015)
- One new migration (SQL shown to the lead): `repost` + indexes, `post.repost_count`, notification CHECKs extended to `repost`; hand-written objects of CLAUDE.md never dropped.
- `PUT/DELETE /posts/{postId}/repost`: idempotent, counter only on real insert/delete, `repost` notification aggregated/undone like `post_like`, no self-notification (own post is 422 anyway). Tests: restricted-audience post (followers, close friends, private) → 422 `repost_not_allowed` when visible, 404 when not; own → 422 `cannot_repost_own`; unverified → 403; 31st repost in an hour → 429.
- `Post.repostCount/repostedByMe/canRepost/repostedBy/repostedAt` hydrated without N+1 (query count asserted on a 20-post page).
- `/feed/following` per the new description (one row per post at latest activity, `repostedBy` of that activity, old cursor → 400) and `/users/{username}/reposts`. Live filter tests: track turned `followers` after the repost → repost gone from both lists for a non-follower; reposter suspended → gone; post hidden → gone; counts unchanged.
- §13b: deleting a reposter decrements `repost_count`; post deletion cascades. EXPLAIN of the following feed on 10 k posts / 5 k reposts in the handoff.

**W-40 · backend · W-39, W-32, W-80** Recommendations v1 (replaces the W-39 ranking)
- `RecomputeAffinity`, `RecomputeTrending` (scheduled) and `SqlFeedRanker` per ADR 005 **amended by ADR 015 §6** (F = 0.75 when a followed user reposted p in the last 7 days; post trending repost weight 4; viewer's reposts weight 3 in affinity); weights in one class, unit-tested on a fixture dataset (genre-affine user gets that genre ranked first; post reposted by a followee ranks above an equal unrelated post).
- DI alias `FeedRanker` → `SqlFeedRanker`, `SimpleFeedRanker` deleted; `/feed/for-you` contract unchanged (cursor `r` changes: old cursors → 400, the client restarts, W-34); `repostedBy` null there.
- W-39's behavioural tests (exclusions, ≤ 2 per author per page, fallback, no repeat within a session) pass unchanged against `SqlFeedRanker`; p95 < 150 ms on demo seed (EXPLAIN attached to PR).

**W-41 · backend · W-04, W-30** Search and hashtags
- `PostgresSearchProvider` for the 3 remaining types (users W-27, tracks W-29a keep their rules and ranking); `beyonce` finds `Beyoncé`, `lof` finds `lofi` (prefix), `daft pnk` finds `Daft Punk` (trigram).
- `/search` without type returns 5 sections ≤ 5 items; with type paginates up to 200; private/hidden never returned. Catalogue artists are **not** a `/search` section (ADR 014 §3).
- `GET /hashtags/{name}` and `/hashtags/{name}/posts`.

**W-42 · media-infra · W-10, W-31, W-40, W-80** Demo seed
- `make seed` generates audio with FFmpeg synthesis only (no external files): ≥ 30 users, 40 artists, 150 tracks over ≥ 10 genres, 300 posts with clips/visuals, follows, likes, listens, comments, ≥ 100 reposts of public posts.
- Seed is deterministic (fixed random seed) and runs in < 5 min; ends with affinity + trending recomputed.

**W-82 · backend · W-70** Admin dashboard series (FB-31)
- `GET /admin/dashboard/series?range=7d|30d` per contract and DATA_MODEL §18: one grouped query on `activity_event`, alerts by `first_seen_at`, days in Europe/Paris with zero-filled gaps, cache 5 min per (range, role level); bad `range` → 422.
- Tests: anonymous 401, user 403; moderator gets `moderation` only (others null); events at 23:30 UTC on the day before a DST change land on the right local day; arrays length = `days` length (7/30), last = today Paris.
- EXPLAIN on 100 k events (index `(category, type, created_at)` used) in the handoff.

**W-83 · frontend · W-82, W-73** Admin charts (FB-31)
- Dashboard: range toggle 7 j / 30 j, one small chart per series group (activity, content, moderation, alerts by severity) with design tokens for light/dark (FB-27), French day labels, accessible table fallback (`<table>` visually hidden), empty/error states; moderator sees the moderation chart only.
- No chart library above ~40 kB gzip in the admin chunk (lazy chunk unchanged for other users).

**W-84 · backend · —** External catalogue (FB-06, ADR 014)
- `CatalogProvider` (CONVENTIONS §7), `DeezerCatalogProvider`, `NullCatalogProvider`, `CATALOG_PROVIDER` flag; `/catalog/artists`, `/catalog/charts/artists`, `/catalog/artists/{externalId}`; `Me.features.catalog`.
- Tests with `MockHttpClient` only (never the real Deezer in CI): flag `none` → 404 on the 3 routes and `features.catalog=false`; timeout, HTTP 5xx, 200 + `{"error":…}` and outbound limiter exhausted → 200 `available:false` (lists) / stale metadata or 503 `catalog_unavailable` + `Retry-After` (artist), never 500; bad id (`dz:abc`, `../`) → 404 with no outbound call; cache hit makes no outbound call; top tracks re-fetched after 10 min, metadata after 24 h; `previewUrl` returned verbatim, no Wave URL wraps it; attribution present in every response.
- No table, no stored audio; cache pool pruned daily by the scheduler.

**W-85 · media-infra · —** Catalogue wiring (ADR 014 §9)
- CSP of `front-static.conf` and `front-dev.conf`: `https://*.dzcdn.net` added to `img-src` and `media-src` only; check no COEP `require-corp` on the front; `CATALOG_PROVIDER` in compose files (`deezer` dev, `none` prod default) and `.env` docs.
- README prod checklist: non-commercial clause, flag off until agreement, privacy line (preview playback exposes the IP to Deezer's CDN). Curl check of the CSP header in the handoff.

**W-86 · frontend · W-84, W-85** Catalogue UI (Sonnet)
- Shown only when `me.features.catalog`: Découvrir section "Artistes célèbres" (`/catalog/charts/artists`), catalogue artist results under the search box (separate block, never mixed with Wave results), page `/discover/artists/:externalId` with top tracks.
- Preview playback in a dedicated lightweight player (or a `catalog` source in the player store) that never reports listens or grants, has no like/playlist/post action, uses `<audio>` without `crossOrigin`, refetches the artist once on a media error; "Données et extraits : Deezer" visible next to every block, "Écouter sur Deezer" link (`rel="noopener noreferrer"`); `available:false` → section omitted; 503 → retry message.

**W-81 · frontend · W-80** Repost UI (Sonnet)
- Repost button with count on the post (feed and detail), hidden when `!canRepost && !repostedByMe`, optimistic toggle rolled back on error (403 unverified, 422, 429 messages in French); "Reposté par X" header when `repostedBy` is set; profile tab "Reposts" (`/users/{username}/reposts`); notification text "a reposté ton post" (stub added in `p4-contract`); MSW mocks updated with the new `Post` fields.

**W-87 · backend · W-40, W-41** Discover home (FB-32)
- `GET /discover` per contract: 4 sections always present, public-only (ADR 012 §6), tests for each exclusion (self, followed, suspended, restricted track, hidden post, empty hashtag) and the ordering rules; global parts cached 15 min, per-viewer hydration without N+1; EXPLAIN on demo seed in the handoff.

**W-43 · frontend · W-41, W-87 (W-86 optional)** Discovery UI + fill empty pages (FB-32)
- Mock-up first (light and dark, `docs/design/`), validated by the user before code.
- Découvrir (`/discover`, W-38 shell): with an empty search box, `/discover` sections (trending tracks, genre chips, accounts to follow with inline follow, trending hashtags) + catalogue block when enabled; while typing: search page with type tabs, debounced input (300 ms); hashtag page.
- Desktop side columns: Abonnements (≥ 1024 px) right column = accounts to follow + trending hashtags; Pour toi (≥ 1280 px) left column = same, keeping the FB-26 right panel; Abonnements with no follow shows the suggestions inline instead of an empty state. One shared query (`/discover`, stale 5 min).
- Empty and error states for every list.

**W-44 · qa-security · W-40, W-41, W-80, W-82, W-84, W-87** Phase 4 review
- Search SQL injection / tsquery syntax fuzzing (no 500); query-cost check on 100-char inputs. EXPLAIN review of feed and search on seed data.
- Repost: no audience leak (restricted post never reachable through a repost, after audience change, unfollow, suspension, hiding), counters and notifications under concurrency, rate limits.
- Catalogue: SSRF/path injection through `externalId` and `q`, no Deezer audio through Wave, CSP limited to `*.dzcdn.net`, provider errors never 500, flag off hides everything. Admin series: role split, no per-user data.

## Phase 5 — Tests, hardening, moderation UI, README

**W-50 · frontend · W-33** Moderation UI — **absorbed by W-74 (FB-28, ADR 013), do not implement separately**
- `/admin` visible only with `ROLE_MODERATOR`; queue grouped by target with preview, reasons, counts; decision buttons with note; per-target report list.

**W-51 · backend · all** Test coverage pass
- Functional test per operation of `openapi.yaml` (status + schema validation against the spec); line coverage of `src/` ≥ 70 %.

**W-52 · frontend · all** Front test pass
- Vitest for player store, API client refresh, feed view batching, playlist reorder; ESLint/Prettier clean; Lighthouse mobile ≥ 85 performance on feed.

**W-53 · media-infra · W-01, W-02** Prod profile
- `compose.prod.yaml`: TLS-ready nginx, `REFRESH_COOKIE_SECURE=1`, no dev ports, restart policies, healthchecks, Postgres + MinIO backups script.
- Real email delivery (FB-01): transactional provider (Brevo/Mailgun/Postmark or SMTP relay) configured through `MAILER_DSN`; SPF, DKIM and DMARC published for the sending domain and verified (mail-tester score ≥ 9/10); Mailpit absent from prod.
- Prod object storage: bucket CORS restricted to `POST` from `FRONT_PUBLIC_URL` (ADR 009); private-bucket read path settled (ADR 003 amendment).
- `README.md` (French): install, `make` targets, architecture, prod checklist (secrets rotation, versions, audit).

**W-55 · backend · W-33, W-40** Account deletion (GDPR)
- `DELETE /me` with password per DATA_MODEL §13b: wrong password 422; success 204, cookie cleared, refresh token → 401, login → 401.
- After deletion no row references the user (query every table) and counters of surviving rows are exact (followers, likes, comment counts, playlist item counts/positions, hashtag counts); other users' posts on the user's tracks are gone.
- Activity journal (FB-28, DATA_MODEL §13b): no non-admin `activity_event` row about the user remains; `admin.*` rows survive with NULL user columns; alerts about the user are gone.
- `PurgeObjects` dispatched for every collected key (asserted against the in-memory storage); confirmation email in Mailpit; user with 100 tracks / 500 posts deleted in < 5 s.

**W-56 · frontend · W-55** Delete-account UI
- Settings → "Delete my account": explicit consequences text, password field, typed confirmation (`username`), then logout and redirect to home.

**W-54 · qa-security · all** Final security sign-off
- `make audit` green; W-77 (back-office review) closed; full `tests/Security` suite green, including deletion (another user cannot delete my account; no residual personal data in DB or storage); `docs/SECURITY.md` final with residual risks; every open finding closed or accepted by the user.
