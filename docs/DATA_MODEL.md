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
| `Visibility` | `public`, `followers`, `close_friends`, `private` (FB-18, ADR 012; `private` = owner only) |
| `Moderation` | `visible`, `hidden` |
| `FailureReason` | `invalid_media` (unreadable/disguised), `no_audio` (no audio stream, e.g. silent video), `unsupported_codec`, `too_long`, `too_large`, `processing_error` |
| `VisualType` | `image`, `video` |
| `ListenSource` | `player`, `feed` |
| `AffinityKind` | `genre`, `artist` |
| `ReportTargetType` | `track`, `post`, `comment`, `playlist`, `artist`, `user` |
| `ReportReason` | `spam`, `harassment`, `hate`, `sexual`, `violence`, `copyright`, `impersonation`, `other` |
| `ReportStatus` | `open`, `actioned`, `dismissed` |
| `NotificationType` | `follow`, `track_like`, `playlist_like`, `post_like`, `comment` (FB-16) |
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

Entity extends gesdinet `RefreshToken` mapped superclass: `id` int identity, `refresh_token` varchar(128) unique, `username` = user id string, `valid` **`timestamp(0) without time zone`** (gesdinet limitation, kept; values are UTC by convention: PHP and Postgres session timezone are UTC). Added columns (ADR 008):

| Column | Type | Null | Rules |
|---|---|---|---|
| family_id | uuid | no | same for every token of one login session; new family at login/register |
| rotated_at | timestamp(0) | yes | set when the token is exchanged; rotated tokens are **kept** until `valid` to detect reuse |

Indexes: (family_id), (username). TTL 30 days, sliding: each rotation issues a child with a fresh 30-day `valid`.
Rules: presenting a token with `rotated_at` set → `DELETE … WHERE family_id = :f` + 401 `token_reused`. Logout deletes the family. `POST /me/password` deletes every family of the user except the current `sid`, then rotates the current one. Password-reset confirm, suspension and account deletion delete all rows for the user. The access JWT carries `sid` = family_id. Expired rows purged daily (`gesdinet:jwt:clear` via Scheduler).

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
| name | varchar(50) | no | display name, **French** (FB-08), e.g. `Rap français`, `Électro` |
| position | smallint | no | display order (0 first); `GET /genres` sorts by it |

Seeded **by migration** (not fixtures): 25 genres in `Version20261024000100`, 20 added + French names + reorder in `Version20261027000600` (FB-08). Slugs never change (tracks keep their genre); a removed genre must remap its tracks (FK RESTRICT).
45 genres, in `position` order: `rap, rap-fr, hip-hop, pop, afro, rnb, afrobeats, trap, drill, amapiano, dancehall, reggaeton, zouk, kompa, coupe-decale, rai, variete-fr, chanson, electronic, house, techno, edm, drum-and-bass, lofi, beats, instrumental, rock, indie, metal, punk, jazz, blues, soul, funk, gospel, reggae, latin, k-pop, classical, folk, country, ambient, soundtrack, world, other`.
The migration is the source of truth for display names.

## 6. Track — `track`

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| artist_id | uuid FK → artist | no | ON DELETE RESTRICT |
| owner_id | uuid FK → app_user | no | denormalized = artist.owner_id; ON DELETE CASCADE; kept in sync when artist changes (only owner's own artists allowed) |
| genre_id | varchar(32) FK → genre | no | ON DELETE RESTRICT |
| title | varchar(120) | no | 1–120 |
| cover_key | varchar(255) | yes | public area |
| raw_key | varchar(255) | yes | private quarantine key copied from the consumed `track_upload`; nulled after successful processing |
| audio_key | varchar(255) | yes | private `audio/…`; set when ready |
| duration_ms | int | yes | set by worker (ffprobe), ≤ 600 000 |
| size_bytes | int | no | real object size read from storage at creation; ≤ 209 715 200 (MP4) / 31 457 280 (other), enforced again by the worker |
| status | varchar(16) | no | `MediaStatus`, default processing |
| failure_reason | varchar(32) | yes | `FailureReason`, only when failed |
| visibility | varchar(16) | no | `Visibility`, default public; audience of the track and of its owner's posts (FB-18, ADR 012) |
| allow_clips | bool | no | default true (D5) |
| moderation | varchar(16) | no | default visible |
| play_count | int | no | counter (counted Listen) |
| like_count | int | no | counter (TrackLike) |
| trending_score | double precision | no | default 0, *job* RecomputeTrending |
| search_vector | tsvector | no | trigger: title (A) + artist.name (B); artist rename trigger refreshes its tracks |
| created_at | timestamptz | no | |
| updated_at | timestamptz | no | |

Indexes: (artist_id, created_at DESC, id DESC), (owner_id, created_at DESC, id DESC), (genre_id), partial (trending_score DESC, id DESC) WHERE status='ready' AND visibility='public' AND moderation='visible', GIN(search_vector), GIN trgm on `wave_unaccent(lower(title))`.

**Track is "public-visible"** ⇔ status=ready ∧ visibility=public ∧ moderation=visible. Used only where the result is the same for every viewer: `artist.track_count`, the trending index, phase-4 discovery (trending, Découvrir, recommendations).
**Viewer v is in the audience of track t** (FB-18, ADR 012) ⇔ v = t.owner ∨ t.visibility=public ∨ (t.visibility=followers ∧ a `follow` row (v → t.owner) exists) ∨ (t.visibility=close_friends ∧ a `close_friend` row (t.owner, v) exists). `private` = owner only.
**Track visible to v** ⇔ moderator ∨ v = t.owner ∨ (status=ready ∧ moderation=visible ∧ v in the audience of t). Everything else → 404 `not_found`, identical to an unknown id, on every route (read, like, stream-url, listens, playlist add, post create, report). This one rule serves the track lists (`/users/{u}/tracks`, `/artists/{slug}/tracks`, `/me/likes/tracks`), search, playlist items and covers, likes, stream-url, listens, `/stream` track tokens (re-checked per request, so an unfollow cuts playback at the next Range request), reports and notifications. The owner sees own tracks with `status`, `failureReason`, `moderation`, `visibility`; others get `visibility` = `public` or null (ADR 012 §7). Moderators see everything.
SQL form (hot queries, ADR 012 §8) — one shared fragment, never re-typed per repository: `t.visibility = 'public' OR t.owner_id = :v OR (t.visibility = 'followers' AND t.owner_id IN (SELECT followed_id FROM follow WHERE follower_id = :v)) OR (t.visibility = 'close_friends' AND t.owner_id IN (SELECT owner_id FROM close_friend WHERE friend_id = :v))` (uncorrelated `IN` → hashed SubPlans computed once per query).

## 6b. TrackUpload — `track_upload` (ADR 009)

| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | = uploadId |
| user_id | uuid FK → app_user | no | CASCADE; upload usable only by this user (else 404) |
| quarantine_key | varchar(255) | no | UNIQUE, `quarantine/uploads/{userId}/{uploadId}` |
| declared_filename | varchar(255) | no | |
| declared_size | int | no | |
| declared_type | varchar(32) | no | declared MIME |
| max_bytes | int | no | 209 715 200 if extension `.mp4`, else 31 457 280; written into the POST policy |
| status | varchar(16) | no | `pending` → `consumed` \| `expired` |
| expires_at | timestamptz | no | presigned policy expiry = created + 15 min |
| consumed_at | timestamptz | yes | |
| track_id | uuid FK → track | yes | SET NULL; set on consume |
| created_at | timestamptz | no | |

Indexes: (user_id, status), (status, created_at). Consume = `UPDATE track_upload SET status='consumed', consumed_at=now(), track_id=:t WHERE id=:id AND user_id=:u AND status='pending' AND created_at > now() - interval '2 hours' RETURNING …` in the track-creation transaction (single use, race-free). Max 10 `pending` per user. `CleanupQuarantine` marks `pending` rows older than 2 h `expired` and deletes their object; rows older than 7 days are deleted.

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

Indexes: (owner_id, created_at DESC, id DESC), GIN(search_vector). Visible to others ⇔ is_public ∧ moderation=visible, else 404. Max 500 items (409 `playlist_full`). Max 200 playlists per owner (409 `playlist_limit_reached`, counted under a `SELECT … FOR UPDATE` of the owner's `app_user` row, same as the artist limit). No cover upload in v1: `coverUrl` = cover of the first item visible to the viewer (§6).

`playlist_item`
| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| playlist_id | uuid FK | no | CASCADE |
| track_id | uuid FK | no | CASCADE (processor of track delete decrements `item_count` first) |
| position | int | no | 0-based, contiguous |
| added_at | timestamptz | no | |

Constraints: UNIQUE(playlist_id, track_id); UNIQUE(playlist_id, position) **DEFERRABLE INITIALLY DEFERRED** (reorder rewrites all positions in one transaction). Add = append at `item_count`. Remove = delete + `position = position - 1` for following items. Reorder = `PUT …/order` with the complete ordered list of trackIds (must equal the current set, else 409 `playlist_order_mismatch`). Only ready tracks visible to the adder can be added (a follower may add a `followers` track to their public playlist; other viewers simply do not get that item). Items whose track is or later becomes invisible to a viewer (audience change, unfollow, removal from close friends): owner gets them with `trackId` and `track: null`; non-owners do not get them at all (filtered in SQL, positions may show gaps). `trackIds` of a reorder must be a JSON list, else 422.

## 8. Social graph and likes

| Table | Columns | PK / constraints | FKs | Indexes | Counters touched |
|---|---|---|---|---|---|
| `follow` | follower_id, followed_id, created_at | PK(follower_id, followed_id), CHECK follower≠followed | both → app_user CASCADE | (followed_id, created_at DESC) | follower.following_count, followed.followers_count |
| `track_like` | user_id, track_id, created_at | PK(user_id, track_id) | CASCADE both | (track_id), (user_id, created_at DESC) | track.like_count |
| `playlist_like` | user_id, playlist_id, created_at | PK(user_id, playlist_id) | CASCADE both | (playlist_id), (user_id, created_at DESC) | playlist.like_count |
| `post_like` | user_id, post_id, created_at | PK(user_id, post_id) | CASCADE both | (post_id), (user_id, created_at DESC) | post.like_count |

Like/follow writes are idempotent: `INSERT … ON CONFLICT DO NOTHING RETURNING` and increment only if a row was inserted; delete increments −1 only if a row was deleted. Liking own content is allowed. A user can like/follow only what they can see (else 404). Unlike: an existing like of the caller is deleted whatever the target's visibility; without one, an invisible target → 404 (no existence oracle). Unfollow: any existing user, suspended included (W-26 F14). Inserted follows/likes write a notification and unfollow/unlike remove the actor from it (§8b, phase 3). Suspended users (`suspended_at` not null) are excluded from followers/following lists by a join on `app_user`; counters keep counting them until phase 5 moderation.
Unfollow also removes the follower from the followed user's close friends (`close_friend` below, FK cascade, same statement).

`close_friend` (FB-18, ADR 012) — one list per user, applies to all their `close_friends` tracks
| Column | Type | Null | Rules |
|---|---|---|---|
| owner_id | uuid FK → app_user | no | CASCADE; the list owner |
| friend_id | uuid FK → app_user | no | CASCADE; must follow the owner |
| created_at | timestamptz | no | |

PK(owner_id, friend_id); CHECK owner_id ≠ friend_id; **FK (friend_id, owner_id) → follow(follower_id, followed_id) ON DELETE CASCADE** (members are followers, enforced by the DB; unfollow removes the membership; re-following does not restore it). Index (friend_id, owner_id) (audience subquery + cascade on unfollow). No counter, no notification, no size limit beyond the follower count. Add = `INSERT … ON CONFLICT DO NOTHING`; a non-follower (or the FK firing because of a concurrent unfollow, SQLSTATE 23503) → 422 `not_a_follower`. Visible only to its owner through `/me/close-friends*`; suspended members are left out of the list (join on `app_user`), their row stays.

## 8b. Notification — `notification`, `notification_actor` (FB-16, phase 3, W-45)

`notification` (one row = one aggregated item of the bell)
| Column | Type | Null | Rules |
|---|---|---|---|
| id | uuid PK | no | |
| recipient_id | uuid FK → app_user | no | CASCADE |
| type | varchar(16) | no | `NotificationType` |
| track_id | uuid FK → track | yes | CASCADE; set iff type=`track_like` |
| playlist_id | uuid FK → playlist | yes | CASCADE; set iff type=`playlist_like` |
| post_id | uuid FK → post | yes | CASCADE; set iff type ∈ {`post_like`, `comment`} |
| read_at | timestamptz | yes | null = unread |
| created_at | timestamptz | no | |
| updated_at | timestamptz | no | bumped when an actor joins |

CHECK: exactly the target column of its type is set (all three null for `follow`). Indexes: (recipient_id, updated_at DESC, id DESC); **unique** `(recipient_id, type, track_id, playlist_id, post_id) NULLS NOT DISTINCT WHERE read_at IS NULL` (PG ≥ 15, hand-written in the migration, remove from `diff` output like GIN indexes) = one unread item per (recipient, type, target).
`notification_actor`: notification_id FK CASCADE, actor_id FK → app_user CASCADE, created_at; PK(notification_id, actor_id); index (actor_id).

Events (same transaction as the action, only when it really inserted a row, so idempotent PUTs notify once):
| Action | type | recipient | target |
|---|---|---|---|
| follow inserted (§8) | `follow` | followed user | — |
| track like inserted | `track_like` | track.owner_id | track |
| playlist like inserted | `playlist_like` | playlist.owner_id | playlist |
| post like inserted | `post_like` | post.author_id | post |
| comment created (§10) | `comment` | post.author_id | post |
Write = `INSERT INTO notification … ON CONFLICT (…) WHERE read_at IS NULL DO UPDATE SET updated_at = now() RETURNING id`, then `INSERT INTO notification_actor … ON CONFLICT DO NOTHING`. Concurrent events on one target merge into one row (no lock needed). **No notification to oneself**: actor = recipient → nothing written (liking or commenting one's own content).
Aggregation: while unread, every new actor joins the same row; after `POST /me/notifications/read` (`read_at = now()` where `updated_at ≤ upTo`), the next event opens a new row. A like/unlike loop therefore yields at most one new item per read by the recipient (no spam).
Undo: unfollow / unlike delete the actor's `notification_actor` rows for that (recipient, type, target), read or not, then delete those notifications left without actor. Comment deletion or hiding does **not** touch notifications (no comment body is ever stored or shown, so nothing leaks).
Cleanup: target deleted → FK CASCADE removes the row (track, playlist, post, and by cascade every path of §9 and §13b); recipient deleted → CASCADE; actor deleted → its actor rows CASCADE, and an item left without actor is omitted at read time (and deleted by `DELETE /me` step 4, set-based).
Read-time filters (list and unread count use the same query): recipient = caller only (no id in any route, no IDOR surface); actors with `suspended_at` not null are left out of `actors` and `actorCount`, items with no remaining actor are omitted; the target must be visible to the recipient by the rule of its type (track §6 — always true for the owner; playlist §7 — idem; post "visible to a caller" §9, so a post on a track that became invisible to its author is omitted). Hidden own content stays listed (the owner sees it anyway). Retention: read items older than 90 days purged in phase 5 (W-50 or a scheduler job), not before.

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
**All phase-3 tables** (`post`, `hashtag`, `post_hashtag`, `post_like`, `comment`, `post_view`, `report`, `notification`, `notification_actor` §8b) are created by one W-30 migration, so W-32, W-33 and W-39 add behaviour without migrations. Exception FB-18 (W-47): a **new** migration (W-30's is already applied in dev, never edited) replaces the `track.visibility` CHECK with the 4 values and creates `close_friend` with its PK, CHECK, both `app_user` FKs, the composite FK to `follow` and the `(friend_id, owner_id)` index; `down` refuses (or maps `followers`/`close_friends` → `private`) before restoring the 2-value CHECK.

Creation rule (D5): track must be ready, moderation=visible and either `track.owner_id = author_id` (any visibility: the post gets the track's audience) or (`visibility = public` ∧ `allow_clips`).
Creation errors (W-30, no existence oracle): track unknown or invisible to the caller (others' private/processing/failed/hidden track) → 404 `not_found`, identical to an unknown id; others' track visible to the caller but not (public ∧ `allow_clips`) — `allow_clips = false`, or a `followers`/`close_friends` track the caller is in the audience of → 422 `clips_not_allowed`; own track hidden by moderation → 422 `clips_not_allowed`; own track processing or failed → 409 `track_not_ready`; range → 422 `clip_out_of_range`.
**Post is "playable" for viewer v** (FB-18: viewer-dependent) ⇔ status=ready ∧ post.moderation=visible ∧ track.moderation=visible ∧ author.suspended_at IS NULL ∧ ((track.visibility=public ∧ track.allow_clips) ∨ (author = track.owner ∧ v in the audience of the track, §6)). A post on the owner's own `followers` / `close_friends` / `private` track therefore has exactly the track's audience, and changing the track's visibility (or an unfollow, or a close-friends edit) applies to it at the next request; nothing is rewritten. Playable for v ⇒ track visible to v. Posts not playable for v are 404 for v except for the author (who sees them with their status) and moderators; they are excluded from v's feeds, hashtag pages and profile lists.
**Post visible to a caller** ⇔ moderator ∨ playable ∨ (caller = author ∧ track visible to the caller, §6). A post whose track the caller cannot see is 404 even for its author (the track's metadata must not leak); the author can still `DELETE` it (removing one's own content works whatever the visibility, as for likes, W-26 F14). A suspended author's own requests are already refused at authentication.
`Post.clip` is non-null only when status=ready and the post is visible to the caller. Issuing it (embedded in any Post payload or via `GET /posts/{id}/stream-url`) writes the listen grant of (caller, post.track) (§11), one multi-row upsert per response.
**Post deletion** (author, moderator, or cascade from track/user deletion): in the same transaction `hashtag.post_count − 1` for each of its hashtags, delete `report` rows targeting the post and its comments, dispatch `PurgeObjects` for clip (private) and visual/poster (public) keys. Track deletion (phase 1 processor) now runs this for every post on the track before the cascade.
Hiding a post (moderation) leaves `hashtag.post_count` unchanged (recount in phase 4 search if needed).

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

Flat (no replies). Index (post_id, created_at DESC, id DESC). Delete allowed for comment author, post author, moderator. `post.comment_count`: +1 create, −1 delete of visible comment, −1 on hide, +1 on restore. Moderation changes are conditional (`UPDATE comment SET moderation=:m WHERE id=:id AND moderation<>:m`) and adjust the counter only when a row changed, so repeated hide/dismiss never drift.
**Comment visible to a caller** ⇔ its post is visible to the caller ∧ (moderator ∨ caller = comment author ∨ (moderation=visible ∧ comment author not suspended)). Invisible → 404 `not_found` on every route (delete, report), identical to an unknown id; visible but not deletable by the caller → 403. Comments by suspended authors are left out of lists; `comment_count` keeps counting them until phase 5 moderation (as followers, W-26 F16).
Create: post must be playable for the caller (else 404); body trimmed, 1–500 chars after trim (whitespace only → 422 `validation_failed`); stored as plain text, never rendered as HTML.

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

Counted ⇔ all of (ADR 010):
1. **duration**: `listened_ms ≥ 30 000` ∨ `listened_ms ≥ 50 % × reference` where reference = track.duration_ms (player) or post.clip_duration_ms (feed); ∧ `listened_ms ≤ reference + 2 000`;
2. **grant**: a `listen_grant` row for (user, track) exists with `issued_at ≥ now − (reference + 30 min)`; it is **consumed** (`DELETE … RETURNING issued_at`) by every listen report that passes the visibility/ready checks, counted or not;
3. **elapsed**: `listened_ms ≤ (now − issued_at) + 2 000` (one cannot have played more than the time since the URL was issued);
4. **anti-replay**: no counted listen for the same (user, track) in the last `max(30 s, reference)` (whole duration);
5. **daily cap**: fewer than 20 counted listens for the same (user, track) in the last 24 h (rolling; `COUNT` on the (user_id, track_id, created_at) index, `LIMIT 20`).
Any rule failing → 200 `counted: false`, nothing stored. Counted → insert row + `track.play_count + 1`, same transaction as the grant consumption. Listens on own tracks are counted too (simplicity; revisit if abused).
Indexes: (user_id, created_at DESC), (track_id, created_at DESC), (user_id, track_id, created_at DESC).

`listen_grant` (W-26 F13, ADR 010) — one row per (user, track), proof that a stream URL was issued
| Column | Type | Null | Rules |
|---|---|---|---|
| user_id | uuid FK | no | CASCADE |
| track_id | uuid FK | no | CASCADE |
| issued_at | timestamptz | no | first issuance of the current play session |

PK(user_id, track_id). Written on each successful `GET /tracks/{id}/stream-url` (and `GET /posts/{id}/stream-url` for the post's track, phase 3) by `INSERT … ON CONFLICT (user_id, track_id) DO UPDATE SET issued_at = now() WHERE listen_grant.issued_at < now() − (track.duration_ms + 30 min)`: a re-fetch during a session (expired URL, seek) keeps the original `issued_at`; a stale grant is renewed. Purge: scheduler job daily, `DELETE WHERE issued_at < now() − 1 day` (*job*). Indexes: PK plus the Doctrine FK indexes on `user_id` and `track_id` (kept: the `track_id` one serves the CASCADE on track delete).

`post_view`
| Column | Type | Null | Rules |
|---|---|---|---|
| user_id | uuid FK | no | CASCADE |
| post_id | uuid FK | no | CASCADE |
| watched_ms | int | no | max of reported values |
| completed | bool | no | watched_ms ≥ 80 % clip duration (sticky) |
| first_seen_at, last_seen_at | timestamptz | no | |

PK(user_id, post_id); index (post_id); index (user_id, last_seen_at, post_id) (for-you fallback, ADR 011). Upsert `INSERT … ON CONFLICT (user_id, post_id) DO UPDATE SET watched_ms = greatest(…), completed = post_view.completed OR excluded.completed, last_seen_at = now() RETURNING (xmax = 0)`; first insert → `post.view_count + 1` in the same transaction. Reported `watchedMs` is clamped to `clip_duration_ms`; duplicate ids in one batch are merged (max). Views of one's own posts and of posts not visible-and-playable for the caller are ignored silently (no row, no counter). Any row = "seen" for for-you (ADR 011).
Feed listens (`source=feed`, W-32): same rules as player listens with reference = `post.clip_duration_ms`; `postId` must be a post of this track **playable for the caller** (§9, viewer-dependent; else 422 `validation_failed`, also for unknown/invisible ids), checked instead of the track visibility; the grant comes from clip issuance (§9).

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
A user cannot report their own content (422 `cannot_report_own`) and can only report what they can see. Check order (W-33): body validation (422) → target visible to the caller, with the visibility rule of its type (track §6, playlist §7, post §9, comment §10, artist: moderation=visible or caller is its owner, user: exists and not suspended), else 404 identical to an unknown id → own content (track/artist/playlist owner, post/comment author, user = self) → 422 `cannot_report_own` → duplicate → 409 `already_reported`.
"Own" for moderators too: a moderator reports like any user and decides through `/admin`.
Concurrency: the report insert and the auto-hide check run in one transaction holding `pg_advisory_xact_lock(hashtext(target_type || ':' || target_id))`, so the 5th and 6th concurrent reporters hide the target exactly once and counters move once.
**Auto-hide**: in the report transaction, if `COUNT(DISTINCT reporter_id) WHERE target AND status='open' ≥ 5` and target_type ∈ {track, post, comment, playlist, artist} → target `moderation = hidden` (counters adjusted, e.g. comment_count, artist track_count recount). `user` targets are never auto-hidden: queue only.
**Moderator decision** (per target, applies to all its open reports): `hide` → moderation=hidden, reports `actioned`; `dismiss` → moderation=visible, reports `dismissed`; `suspend` (user targets only) → `suspended_at = now()`, refresh tokens deleted, reports `actioned`. All set `handled_by_id`, `handled_at`, `decision_note`.
Allowed actions: content targets `hide`/`dismiss` (`suspend` → 422 `validation_failed`); user targets `suspend`/`dismiss` (`hide` → 422); suspending oneself or an already suspended user → 422. Unknown target → 404 (moderators see everything else, hidden included). Moderation changes use the conditional UPDATE of §10 and adjust counters only on an actual change (comment_count, artist track_count recount). Unsuspend is phase 5 (W-50).

## 13b. Account deletion (GDPR erasure) — hard delete

`DELETE /me` (password re-confirmed) **hard-deletes** the user and everything they authored; no anonymised remnants (rationale: ADR 007). One transaction, in this order:
1. Collect every storage key: user avatar; avatars of owned artists; raw/audio/cover keys of owned tracks; clip/visual/poster keys of posts authored by the user **and** of other users' posts using the user's tracks (they are deleted with the track, decision D5 follow-up).
2. Fix counters on rows that survive, with set-based SQL: `followers_count`/`following_count` of the other side of each follow; `like_count` of tracks/playlists/posts the user liked; `comment_count` of posts the user commented (visible comments only); `item_count` of others' playlists containing the user's tracks (then compact `position`); `post_count` of hashtags of deleted posts. `play_count` is **not** decremented (aggregate, not personal data).
3. Delete `report` rows whose target is any deleted row (polymorphic); reports filed by the user go by cascade; `handled_by_id` → NULL by FK.
4. `DELETE FROM track WHERE owner_id = :u` (cascades posts on those tracks, likes, items, listens), then `DELETE FROM artist WHERE owner_id = :u`, then `DELETE FROM app_user WHERE id = :u` (cascades the rest: playlists, posts, comments, likes, follows, close-friend rows on both sides, listens, listen grants, post views, affinity, account tokens, reports filed). Then `DELETE FROM notification n WHERE NOT EXISTS (SELECT 1 FROM notification_actor a WHERE a.notification_id = n.id)` (items whose only actors were the user, §8b). Refresh tokens deleted by `username = id`.
5. Dispatch `PurgeObjects` (batches of ≤ 500 keys) and `SendAccountDeletedEmail` inside the same transaction.
Username and email are free for re-registration immediately. Backups keep the data until their rotation (documented in the README prod checklist; 30 days). Synchronous; target < 5 s for 100 tracks / 500 posts; move to an async `DeleteAccount` message if it ever exceeds that.

## 14. Object storage layout

Two areas behind `ObjectStorage` (CONVENTIONS §7): **private** bucket `wave-private` (never directly reachable; audio served only through signed `/stream` + X-Accel) and **public** bucket `wave-public` (served read-only by nginx at `/media/{key}`, long cache, keys are unguessable and versioned so they are never overwritten).

| Entity | Quarantine (private) | Processed | Output spec |
|---|---|---|---|
| Track audio | `quarantine/uploads/{userId}/{uploadId}` (written by the browser via presigned POST) | private `audio/tracks/{trackId}.m4a` | first audio stream only (`-map 0:a:0 -vn`, video discarded), AAC-LC 160 kb/s, 44.1 kHz stereo, loudnorm I=-14 LUFS TP=-1.5 LRA=11, `-movflags +faststart` |
| Track cover | `quarantine/covers/{trackId}/{uuid}` | public `images/covers/{trackId}/{uuid}.webp` | 800×800 center-crop, WebP q80 |
| User avatar | `quarantine/avatars/users/{userId}/{uuid}` | public `images/avatars/users/{userId}/{uuid}.webp` | 400×400 center-crop, WebP q80 |
| Artist avatar | `quarantine/avatars/artists/{artistId}/{uuid}` | public `images/avatars/artists/{artistId}/{uuid}.webp` | 400×400 |
| Post clip | — (cut from processed track) | private `audio/clips/{postId}.m4a` | same codec, 300 ms fade in/out, faststart |
| Post visual image | `quarantine/posts/{postId}/visual` | public `images/posts/{postId}/{uuid}.webp` | fit within 1080×1920, WebP q80 |
| Post visual video | `quarantine/posts/{postId}/visual` | public `videos/posts/{postId}/{uuid}.mp4` + `images/posts/{postId}/{uuid}-poster.webp` | H.264 main, ≤ 720×1280, ≤ 30 fps, CRF 26, **audio stripped** (`-an`), faststart |

Quarantine objects are deleted after success, kept 7 days after failure (`CleanupQuarantine` daily). Replaced images: old key purged via `PurgeObjects` after the DB points to the new key. Hidden (moderated) public images remain reachable by key in v1 (accepted: unguessable keys, not listed anywhere).
