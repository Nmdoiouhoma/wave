# ADR 012 — Track audiences: public, followers, close friends, private (FB-18)

Status: accepted (user decisions of 10/11; one point to confirm, see end) · 2026-11-10 · Lot `vis-1110`, before phase 3 wave 2.

## Context
FB-18: a non-public track must be visible to (a) its owner only, (b) the owner's followers, or (c) a list of followers the owner picks ("close friends"); unfollowing removes access. Posts on such a track follow the same audience and a change of audience applies to them at once. This ends the phase-3 wave-1 exception "the author's post on their own private track is playable by everyone" (`docs/handoff/phase3-v1-qa.md` point 1).

## Decision
1. **One column, four values.** `track.visibility` ∈ `public`, `followers`, `close_friends`, `private` (`private` keeps its meaning "owner only", so existing rows need no rewrite). No separate audience column: one predicate, one CHECK.
2. **One close-friends list per user**, not per track (Instagram-like): table `close_friend(owner_id, friend_id)`. It applies to every `close_friends` track of the owner. Managed only through `/me/close-friends*` (no id of another user's list in any route, no IDOR surface).
3. **Members are followers, enforced by the database**: composite FK `(friend_id, owner_id) → follow(follower_id, followed_id) ON DELETE CASCADE`. Adding a non-follower fails (422 `not_a_follower`, also when the FK fires in a race with an unfollow); unfollowing deletes the membership in the same statement. Re-following does **not** restore it (the owner must add the person again).
4. **Audience predicate** (DATA_MODEL §6), evaluated live on every request, so an unfollow or an audience change takes effect on the next request (stream tokens included: `/stream` already re-checks visibility per request, T9):
   `in_audience(t, v) ⇔ v = t.owner ∨ t.visibility = public ∨ (t.visibility = followers ∧ v follows t.owner) ∨ (t.visibility = close_friends ∧ (t.owner, v) ∈ close_friend)`.
5. **Posts**: "playable" becomes viewer-dependent: `… ∧ ((track public ∧ allow_clips) ∨ (author = track owner ∧ in_audience(track, viewer)))`. Clipping someone else's non-public track stays impossible (422 `clips_not_allowed` when the caller can see the track, 404 when not).
6. **What stays public-only**: `artist.track_count`, the trending index and every phase-4 discovery surface (trending, Découvrir, recommendations, Deezer mix). Restricted tracks reach people through profiles, artist pages, playlists, search, likes and the two feeds only.
7. **The audience is shown to its owner only**: `Track.visibility` is the real value for the owner and moderators, `public` or `null` for everyone else (null = "shared with a restricted audience that includes you", without saying which).
8. **SQL form**: one shared fragment with *uncorrelated* `IN` subqueries, which PostgreSQL runs once per query as hashed SubPlans (one index range scan each), then probes per row: `t.visibility = 'public' OR t.owner_id = :v OR (t.visibility = 'followers' AND t.owner_id IN (SELECT followed_id FROM follow WHERE follower_id = :v)) OR (t.visibility = 'close_friends' AND t.owner_id IN (SELECT owner_id FROM close_friend WHERE friend_id = :v))`. Indexes: follow PK `(follower_id, followed_id)` (exists), `close_friend (friend_id, owner_id)` (new; also serves the FK cascade on unfollow).

## Consequences
+ No data migration, one new table, one CHECK change; every 404 path keeps its shape (no oracle: an outsider cannot tell a restricted track from an unknown id).
+ Unfollow / audience change / list edit need no fan-out job: nothing is denormalised.
− "Followers" means anyone who taps Suivre (no follow approval in v1): the UI says so.
− Counters stay global (`play_count`, `like_count`, `hashtag.post_count` count every audience); `hashtag.post_count` may exceed what a viewer can open, as already for hidden posts (accepted).
− Public cover objects (`cover_key`) of a restricted track stay reachable by URL for someone who once had access (same as `private` today, T10 accepted).
− A per-track audience list is not possible without a new table (rejected for v1: heavier UI, one list covers the use case).

## Point to confirm by the user
One list per user (reused by all "close friends" tracks), not one list per track.
