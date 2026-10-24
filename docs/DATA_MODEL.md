# Wave — Data model (v1)

Source of truth for the PostgreSQL 16 schema. API shapes live in `openapi.yaml`; this file wins on storage questions, `openapi.yaml` wins on wire format. Changes go through architect.

## 0. Global rules

- **Ids**: UUID v7 (`uuid` column, Symfony `UuidType`), generated in PHP, except `genre.id` (slug, see §5) and composite-PK join tables.
- **Tables**: singular snake_case; `app_user` because `user` is reserved. Columns snake_case; PHP properties camelCase.
- **Timestamps**: `timestamptz`, stored UTC, `DateTimeImmutable`. `created_at` is set in the constructor, never updated.
- **Enums**: PHP backed string enums (`App\Enum\*`), stored as `varchar(16)` + `CHECK` constraint (no PG enum types: they make migrations painful).
- **Counters**: `int NOT NULL DEFAULT 0 CHECK (>= 0)`. Updated **in the same transaction** as the event, with an atomic SQL statement (`UPDATE t SET c = c + 1 WHERE id = :id`), never entity read-modify-write. Exceptions are marked *recount* (recomputed by `COUNT(*)` in the same transaction) or *job* (scheduler).
- **Extensions** (first migration): `citext`, `unaccent`, `pg_trgm`.
- **Immutable unaccent wrapper** (needed for indexes/triggers):
  ```sql
  CREATE FUNCTION wave_unaccent(text) RETURNS text LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
  AS $$ SELECT public.unaccent('public.unaccent'::regdictionary, $1) $$;
  ```
- **Text search config**: `simple` (multilingual, no stemming) over `wave_unaccent(lower(x))`.
- **search_vector columns**: mapped with a custom DBAL type `tsvector`, `insertable: false, updatable: false`; maintained by DB triggers written in migrations. GIN / trigram indexes are created in migrations by hand; **remove them from any `doctrine:migrations:diff` output** (Doctrine cannot express `USING GIN` and will try to drop them).
- **Deletion**: hard delete. When a delete cascades at DB level, the deleting processor must first (a) decrement counters the cascade would otherwise leave stale, (b) collect storage keys and dispatch `PurgeObjects`, (c) delete `report` rows targeting the deleted rows (polymorphic, no FK).

## 1. Enums

| Enum | Values |
|---|---|
| `MediaStatus` | `processing`, `ready`, `failed` |
| `Visibility` | `public`, `private` |
| `Moderation` | `visible`, `hidden` |
| `FailureReason` | `invalid_media`, `unsupported_codec`, `too_long`, `too_large`, `processing_error` |
| `VisualType` | `image`, `video` |
| `ListenSource` | `player`, `feed` |
| `AffinityKind` | `genre`, `artist` |
| `ReportTargetType` | `track`, `post`, `comment`, `playlist`, `artist`, `user` |
| `ReportReason` | `spam`, `harassment`, `hate`, `sexual`, `violence`, `copyright`, `impersonation`, `other` |
| `ReportStatus` | `open`, `actioned`, `dismissed` |
| `Role` | `ROLE_USER` (implicit), `ROLE_MODERATOR`, `ROLE_ADMIN` (inherits moderator) |

## 2. User — `app_user`

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| email | citext | no | UNIQUE, RFC email, ≤ 180 |
| password | varchar(255) | no | hash (`auto` hasher); plain 10–128 chars |
| username | citext | no | UNIQUE, `^[a-z0-9_.]{3,30}$`, stored lowercase, **immutable** in v1; reserved list (`me`, `admin`, `api`, `settings`, `search`, `feed`…) |
| display_name | varchar(50) | no | 1–50, trimmed |
| bio | varchar(300) | yes | |
| avatar_key | varchar(255) | yes | public area key (§14) |
| roles | json | no | default `[]` |
| followers_count | int | no | counter (Follow) |
| following_count | int | no | counter (Follow) |
| email_verified_at | timestamptz | yes | null = unverified (§3b); set by verify-email or password-reset confirm |
| suspended_at | timestamptz | yes | set by moderator; blocks login/refresh |
| created_at | timestamptz | no | |

Indexes: unique(email), unique(username), GIN `gin_trgm_ops` on `wave_unaccent(lower(username))` and on `wave_unaccent(lower(display_name))`.

## 3. RefreshToken — `refresh_token` (gesdinet)

Standard gesdinet mapped superclass (`id` int, `refresh_token` varchar(128) unique, `username` = user id string, `valid` timestamptz). Rotation on (`single_use: true`). TTL 30 days. Password change and suspension delete all rows for the user. Expired rows purged daily (`gesdinet:jwt:clear` via Scheduler).

## 3b. AccountToken — `account_token` (email verification, password reset)

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| user_id | uuid FK → app_user | no | CASCADE |
| purpose | varchar(32) | no | `email_verification` \| `password_reset` |
| token_hash | char(64) | no | UNIQUE, hex SHA-256 of the raw token; raw token = 32 random bytes base64url, sent only by email, never stored nor put in a queue |
| expires_at | timestamptz | no | verification: created + 24 h; reset: created + 1 h |
| used_at | timestamptz | yes | single use |
| created_at | timestamptz | no | |

Index (user_id, purpose). Issuing a new token deletes the user's unused tokens of the same purpose. Lookup by `token_hash` then check `used_at IS NULL AND expires_at > now()`; any failure → 400 `account_token_invalid` (one code, no oracle). Successful use sets `used_at`. Rows older than 7 days purged daily (`PurgeAccountTokens`).
Password-reset confirm also sets `email_verified_at` if null (proves mailbox ownership) and deletes all refresh tokens of the user.

**Unverified accounts** (`email_verified_at IS NULL`) may log in, browse, stream, like, follow, manage playlists and record listens/views. They may **not** (403 `email_not_verified`): upload tracks/covers/avatars, create/edit artists, create posts, comment, report. Email is immutable in v1, so verification is one-shot.

## 4. Artist — `artist` (D6)

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| owner_id | uuid FK → app_user | no | ON DELETE CASCADE |
| slug | varchar(80) | no | UNIQUE, `^[a-z0-9]+(-[a-z0-9]+)*$`, generated from name at creation (ascii-fold, collision → `-2`, `-3`…), **immutable** |
| name | varchar(80) | no | 1–80 |
| bio | varchar(1000) | yes | |
| avatar_key | varchar(255) | yes | public area |
| moderation | varchar(16) | no | `Moderation`, default visible |
| track_count | int | no | *recount*: tracks with status=ready, visibility=public, moderation=visible |
| search_vector | tsvector | no | trigger: name (A) |
| created_at | timestamptz | no | |

Indexes: unique(slug), (owner_id, created_at), GIN(search_vector), GIN trgm on `wave_unaccent(lower(name))`.
Rules: max 10 artists per user. Delete only when the artist has **no tracks at all** (409 `artist_has_tracks`); FK from track is RESTRICT as a backstop.
Auto-creation: on track upload with no `artistId` and the user owns 0 artists → create one named after `display_name`.

**Follow model decision**: users follow **users only**, not artists. An artist always has exactly one owner, so following the owner covers it; the following feed is made of posts (authored by users, not artists); one follow graph keeps the feed query, counters and UI single-path. Artist-level preference is captured implicitly by `user_affinity(kind=artist)`. Artist follow can be added later as a separate table without breaking the API.

## 5. Genre — `genre`

| Column | Type | Null | Rules |
|---|---|---|---|
| id | varchar(32) PK | no | slug, e.g. `hip-hop` |
| name | varchar(50) | no | display name (English) |
| position | smallint | no | display order |

Seeded **by migration** (not fixtures), fixed list: `pop, rock, hip-hop, rnb, electronic, house, techno, drum-and-bass, jazz, blues, classical, folk, country, metal, punk, reggae, latin, afrobeats, soul, funk, lofi, ambient, soundtrack, world, other`.

## 6. Track — `track`

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| artist_id | uuid FK → artist | no | ON DELETE RESTRICT |
| owner_id | uuid FK → app_user | no | denormalized = artist.owner_id; ON DELETE CASCADE; kept in sync when artist changes (only owner's own artists allowed) |
| genre_id | varchar(32) FK → genre | no | ON DELETE RESTRICT |
| title | varchar(120) | no | 1–120 |
| cover_key | varchar(255) | yes | public area |
| raw_key | varchar(255) | yes | private `quarantine/…`; nulled after successful processing |
| audio_key | varchar(255) | yes | private `audio/…`; set when ready |
| duration_ms | int | yes | set by worker (ffprobe), ≤ 600 000 |
| size_bytes | int | no | uploaded file size, ≤ 31 457 280 |
| status | varchar(16) | no | `MediaStatus`, default processing |
| failure_reason | varchar(32) | yes | `FailureReason`, only when failed |
| visibility | varchar(16) | no | default public |
| allow_clips | bool | no | default true (D5) |
| moderation | varchar(16) | no | default visible |
| play_count | int | no | counter (counted Listen) |
| like_count | int | no | counter (TrackLike) |
| trending_score | double precision | no | default 0, *job* RecomputeTrending |
| search_vector | tsvector | no | trigger: title (A) + artist.name (B); artist rename trigger refreshes its tracks |
| created_at | timestamptz | no | |
| updated_at | timestamptz | no | |

Indexes: (artist_id, created_at DESC, id DESC), (owner_id, created_at DESC, id DESC), (genre_id), partial (trending_score DESC, id DESC) WHERE status='ready' AND visibility='public' AND moderation='visible', GIN(search_vector), GIN trgm on `wave_unaccent(lower(title))`.

**Track is "public-visible"** ⇔ status=ready ∧ visibility=public ∧ moderation=visible. Anyone else sees only public-visible tracks; anything else → 404. The owner always sees own tracks (with `status`, `failureReason`, `moderation`). Moderators see everything.

## 7. Playlist — `playlist`, `playlist_item`

`playlist`
| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| owner_id | uuid FK → app_user | no | CASCADE |
| name | varchar(100) | no | 1–100 |
| description | varchar(300) | yes | |
| is_public | bool | no | default true |
| moderation | varchar(16) | no | default visible |
| item_count | int | no | counter (items) |
| like_count | int | no | counter (PlaylistLike) |
| search_vector | tsvector | no | trigger: name (A) + description (B) |
| created_at, updated_at | timestamptz | no | updated_at bumps on item add/remove/reorder |

Indexes: (owner_id, created_at DESC, id DESC), GIN(search_vector). Visible to others ⇔ is_public ∧ moderation=visible, else 404. Max 500 items (409 `playlist_full`). No cover upload in v1: `coverUrl` = cover of the first available item.

`playlist_item`
| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| playlist_id | uuid FK | no | CASCADE |
| track_id | uuid FK | no | CASCADE (processor of track delete decrements `item_count` first) |
| position | int | no | 0-based, contiguous |
| added_at | timestamptz | no | |

Constraints: UNIQUE(playlist_id, track_id); UNIQUE(playlist_id, position) **DEFERRABLE INITIALLY DEFERRED** (reorder rewrites all positions in one transaction). Add = append at `item_count`. Remove = delete + `position = position - 1` for following items. Reorder = `PUT …/order` with the complete ordered list of trackIds (must equal the current set, else 409 `playlist_order_mismatch`). Only ready tracks visible to the adder can be added. Items whose track later becomes invisible to a viewer are returned with `track: null` (owner still gets `trackId`).

## 8. Social graph and likes

| Table | Columns | PK / constraints | FKs | Indexes | Counters touched |
|---|---|---|---|---|---|
| `follow` | follower_id, followed_id, created_at | PK(follower_id, followed_id), CHECK follower≠followed | both → app_user CASCADE | (followed_id, created_at DESC) | follower.following_count, followed.followers_count |
| `track_like` | user_id, track_id, created_at | PK(user_id, track_id) | CASCADE both | (track_id), (user_id, created_at DESC) | track.like_count |
| `playlist_like` | user_id, playlist_id, created_at | PK(user_id, playlist_id) | CASCADE both | (playlist_id), (user_id, created_at DESC) | playlist.like_count |
| `post_like` | user_id, post_id, created_at | PK(user_id, post_id) | CASCADE both | (post_id), (user_id, created_at DESC) | post.like_count |

Like/follow writes are idempotent: `INSERT … ON CONFLICT DO NOTHING RETURNING` and increment only if a row was inserted; delete increments −1 only if a row was deleted. Liking own content is allowed. A user can like only what they can see (else 404).

## 9. Post — `post`, `hashtag`, `post_hashtag`

`post`
| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| author_id | uuid FK → app_user | no | CASCADE |
| track_id | uuid FK → track | no | CASCADE (track owner withdraws → posts go) |
| clip_start_ms | int | no | ≥ 0; start + duration ≤ track.duration_ms |
| clip_duration_ms | int | no | CHECK 15 000–60 000 |
| clip_key | varchar(255) | yes | private `audio/clips/…`, set by GenerateClip |
| visual_type | varchar(16) | yes | `VisualType`; null = no visual (client shows track cover) |
| visual_raw_key | varchar(255) | yes | quarantine key, nulled after processing |
| visual_key | varchar(255) | yes | public area, processed image or video |
| visual_poster_key | varchar(255) | yes | public area, video first frame (video only) |
| caption | varchar(300) | yes | |
| status | varchar(16) | no | `MediaStatus` |
| failure_reason | varchar(32) | yes | |
| moderation | varchar(16) | no | default visible |
| like_count, comment_count, view_count | int | no | counters (PostLike; visible comments; first PostView per user) |
| trending_score | double precision | no | *job* |
| created_at | timestamptz | no | |

Indexes: (author_id, created_at DESC, id DESC), (track_id), partial (created_at DESC, id DESC) WHERE status='ready' AND moderation='visible', partial (trending_score DESC, id DESC) same predicate.

Creation rule (D5): track must be ready, moderation=visible and either `track.owner_id = author_id` or (`visibility = public` ∧ `allow_clips`).
**Post is "playable"** ⇔ status=ready ∧ post.moderation=visible ∧ track.moderation=visible ∧ (author = track.owner ∨ (track.visibility=public ∧ track.allow_clips)). Non-playable posts are 404 for everyone except the author (who sees them with their status) and moderators; they are excluded from feeds, hashtag pages and profiles.

Ready transition (two independent handlers): each handler writes its key then runs
`UPDATE post SET status='ready' WHERE id=:id AND status='processing' AND clip_key IS NOT NULL AND (visual_type IS NULL OR visual_key IS NOT NULL)`.
Any handler failure sets `status='failed'` + `failure_reason`.

`hashtag`: id uuid PK, name varchar(50) UNIQUE (lowercase NFKC, `^[\p{L}\p{N}_]{1,50}$`, stored without `#`), post_count int (counter: +1 per post_hashtag insert, −1 on post delete), created_at. Indexes: unique(name), `name varchar_pattern_ops` (prefix search), GIN trgm(name).
`post_hashtag`: PK(post_id, hashtag_id), post CASCADE, hashtag CASCADE; index (hashtag_id, post_id). Extracted from caption at creation (regex `#([\p{L}\p{N}_]{1,50})`, deduped, max 10; extras ignored). Captions are immutable in v1.

## 10. Comment — `comment`

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| post_id | uuid FK | no | CASCADE |
| author_id | uuid FK | no | CASCADE |
| body | varchar(500) | no | 1–500 after trim |
| moderation | varchar(16) | no | default visible |
| created_at | timestamptz | no | |

Flat (no replies). Index (post_id, created_at DESC, id DESC). Delete allowed for comment author, post author, moderator. `post.comment_count`: +1 create, −1 delete of visible comment, −1 on hide, +1 on restore.

## 11. Listen and PostView

`listen` (only **counted** listens are stored)
| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| user_id | uuid FK | no | CASCADE |
| track_id | uuid FK | no | CASCADE |
| post_id | uuid FK | yes | SET NULL; required when source=feed |
| source | varchar(16) | no | `ListenSource` |
| listened_ms | int | no | |
| created_at | timestamptz | no | |

Counted ⇔ `listened_ms ≥ 30 000` ∨ `listened_ms ≥ 50 % × reference` where reference = track.duration_ms (player) or post.clip_duration_ms (feed); ∧ `listened_ms ≤ reference + 2 000`; ∧ no counted listen for the same (user, track) in the last `max(30 s, reference / 2)`. Counted → insert row + `track.play_count + 1`. Listens on own tracks are counted too (simplicity; revisit if abused).
Indexes: (user_id, created_at DESC), (track_id, created_at DESC), (user_id, track_id, created_at DESC).

`post_view`
| Column | Type | Null | Rules |
|---|---|---|---|
| user_id | uuid FK | no | CASCADE |
| post_id | uuid FK | no | CASCADE |
| watched_ms | int | no | max of reported values |
| completed | bool | no | watched_ms ≥ 80 % clip duration (sticky) |
| first_seen_at, last_seen_at | timestamptz | no | |

PK(user_id, post_id); index (post_id). Upsert; first insert → `post.view_count + 1`. Used to exclude seen posts from for-you.

## 12. UserAffinity — `user_affinity`

| Column | Type | Null | Rules |
|---|---|---|---|
| user_id | uuid FK | no | CASCADE |
| kind | varchar(16) | no | `AffinityKind` |
| key | varchar(64) | no | genre id or artist uuid (string) |
| score | double precision | no | 0..1, normalized per (user, kind): top key = 1 |
| updated_at | timestamptz | no | |

PK(user_id, kind, key); index (user_id, kind, score DESC). Fully rewritten for one user by `RecomputeAffinity` (formula in ADR 005). Keep top 20 keys per kind.

## 13. Report — `report`

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| reporter_id | uuid FK | no | CASCADE |
| target_type | varchar(16) | no | `ReportTargetType` |
| target_id | uuid | no | polymorphic, no FK |
| reason | varchar(16) | no | `ReportReason` |
| details | varchar(1000) | yes | |
| status | varchar(16) | no | default open |
| handled_by_id | uuid FK | yes | SET NULL |
| handled_at | timestamptz | yes | |
| decision_note | varchar(500) | yes | |
| created_at | timestamptz | no | |

UNIQUE(reporter_id, target_type, target_id) → 409 `already_reported`. Indexes: (status, created_at), (target_type, target_id, status).
A user cannot report their own content (422 `cannot_report_own`) and can only report what they can see.
**Auto-hide**: in the report transaction, if `COUNT(DISTINCT reporter_id) WHERE target AND status='open' ≥ 5` and target_type ∈ {track, post, comment, playlist, artist} → target `moderation = hidden` (counters adjusted, e.g. comment_count, artist track_count recount). `user` targets are never auto-hidden: queue only.
**Moderator decision** (per target, applies to all its open reports): `hide` → moderation=hidden, reports `actioned`; `dismiss` → moderation=visible, reports `dismissed`; `suspend` (user targets only) → `suspended_at = now()`, refresh tokens deleted, reports `actioned`. All set `handled_by_id`, `handled_at`, `decision_note`.

## 13b. Account deletion (GDPR erasure) — hard delete

`DELETE /me` (password re-confirmed) **hard-deletes** the user and everything they authored; no anonymised remnants (rationale: ADR 007). One transaction, in this order:
1. Collect every storage key: user avatar; avatars of owned artists; raw/audio/cover keys of owned tracks; clip/visual/poster keys of posts authored by the user **and** of other users' posts using the user's tracks (they are deleted with the track, decision D5 follow-up).
2. Fix counters on rows that survive, with set-based SQL: `followers_count`/`following_count` of the other side of each follow; `like_count` of tracks/playlists/posts the user liked; `comment_count` of posts the user commented (visible comments only); `item_count` of others' playlists containing the user's tracks (then compact `position`); `post_count` of hashtags of deleted posts. `play_count` is **not** decremented (aggregate, not personal data).
3. Delete `report` rows whose target is any deleted row (polymorphic); reports filed by the user go by cascade; `handled_by_id` → NULL by FK.
4. `DELETE FROM track WHERE owner_id = :u` (cascades posts on those tracks, likes, items, listens), then `DELETE FROM artist WHERE owner_id = :u`, then `DELETE FROM app_user WHERE id = :u` (cascades the rest: playlists, posts, comments, likes, follows, listens, post views, affinity, account tokens, reports filed). Refresh tokens deleted by `username = id`.
5. Dispatch `PurgeObjects` (batches of ≤ 500 keys) and `SendAccountDeletedEmail` inside the same transaction.
Username and email are free for re-registration immediately. Backups keep the data until their rotation (documented in the README prod checklist; 30 days). Synchronous; target < 5 s for 100 tracks / 500 posts; move to an async `DeleteAccount` message if it ever exceeds that.

## 14. Object storage layout

Two areas behind `ObjectStorage` (CONVENTIONS §7): **private** bucket `wave-private` (never directly reachable; audio served only through signed `/stream` + X-Accel) and **public** bucket `wave-public` (served read-only by nginx at `/media/{key}`, long cache, keys are unguessable and versioned so they are never overwritten).

| Entity | Quarantine (private) | Processed | Output spec |
|---|---|---|---|
| Track audio | `quarantine/tracks/{trackId}/source` | private `audio/tracks/{trackId}.m4a` | AAC-LC 160 kb/s, 44.1 kHz stereo, loudnorm I=-14 LUFS TP=-1.5 LRA=11, `-movflags +faststart` |
| Track cover | `quarantine/covers/{trackId}/{uuid}` | public `images/covers/{trackId}/{uuid}.webp` | 800×800 center-crop, WebP q80 |
| User avatar | `quarantine/avatars/users/{userId}/{uuid}` | public `images/avatars/users/{userId}/{uuid}.webp` | 400×400 center-crop, WebP q80 |
| Artist avatar | `quarantine/avatars/artists/{artistId}/{uuid}` | public `images/avatars/artists/{artistId}/{uuid}.webp` | 400×400 |
| Post clip | — (cut from processed track) | private `audio/clips/{postId}.m4a` | same codec, 300 ms fade in/out, faststart |
| Post visual image | `quarantine/posts/{postId}/visual` | public `images/posts/{postId}/{uuid}.webp` | fit within 1080×1920, WebP q80 |
| Post visual video | `quarantine/posts/{postId}/visual` | public `videos/posts/{postId}/{uuid}.mp4` + `images/posts/{postId}/{uuid}-poster.webp` | H.264 main, ≤ 720×1280, ≤ 30 fps, CRF 26, **audio stripped** (`-an`), faststart |

Quarantine objects are deleted after success, kept 7 days after failure (`CleanupQuarantine` daily). Replaced images: old key purged via `PurgeObjects` after the DB points to the new key. Hidden (moderated) public images remain reachable by key in v1 (accepted: unguessable keys, not listed anywhere).
