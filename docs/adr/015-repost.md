# ADR 015 — Repost of a post (FB-33)

Status: proposed (1 point to confirm by the user, see end) · 2026-11-13 · Lot `p4-contract`, phase 4.

## Context
FB-33: republish someone else's post to my followers and on my profile, with a visible count. Audiences are viewer-dependent (ADR 012, DATA_MODEL §9 "playable"), evaluated live, never denormalised; feeds are Abonnements (keyset on time) and Pour toi (`FeedRanker`, ADR 005 / W-40).

## Decision
1. **Plain repost, no quote.** Table `repost(user_id, post_id, created_at)`, PK(user_id, post_id), both FKs CASCADE; counter `post.repost_count` (atomic, like `like_count`). Write = `INSERT … ON CONFLICT DO NOTHING RETURNING`, counter +1 only on insert; undo = delete, −1 only on delete.
2. **Repostable** ⇔ the post is playable for the caller ∧ *publicly playable* (track.visibility = public ∧ (track.allow_clips ∨ author = track.owner)) ∧ author ≠ caller. So a reposter's audience can never see more than the post's own audience: a post with a followers / close-friends / private audience is never repostable. Errors: post not playable for the caller → 404 `not_found`; own post → 422 `cannot_repost_own`; visible but restricted audience → 422 `repost_not_allowed`. `Post.canRepost` exposes the rule so the button is hidden instead.
3. **Live filter at read time**: a repost is shown only while the post is publicly playable **and** playable for the viewer **and** the reposter is not suspended. If the track later goes non-public, is hidden, or the author is suspended, the reposts disappear without any rewrite (rows kept, `repost_count` unchanged, as for likes in ADR 012).
4. **Abonnements**: each post appears at most once, at its latest *activity* among its creation by a followed author and its reposts by followed users (viewer's own posts excluded from the repost path). Order and keyset on `(activity_at DESC, post_id DESC)`; `Post.repostedBy` = the followed reposter of that latest activity (null when the latest activity is the authorship) and `repostedAt` its time. Cursor version bumped: old cursors → 400 `invalid_cursor`. A post can move up between pages when a new repost lands; clients already dedupe by id.
5. **Profile**: `GET /users/{username}/reposts`, newest repost first, keyset `(repost.created_at, post_id)`, `repostedBy` = that user, same live filter. Shown to anyone who can see the profile.
6. **Pour toi (W-40 ranker)**: F gains a middle value — 1 follows author; **0.75 a followed user reposted p in the last 7 days**; 0.5 a followed user liked p in the last 7 days; else 0. Trending post score adds repost weight 4 (as a comment); affinity counts the viewer's own reposts with weight 3 (between post like 2 and track like 3, cap unchanged). `repostedBy` stays null in Pour toi.
7. **Notifications**: new `NotificationType` `repost` (recipient = post author, target = post), aggregated and undone exactly like `post_like` (§8b); no notification to the reposter's followers (the feed carries it).
8. **Deletion**: post deleted → reposts CASCADE (post gone, nothing to fix). Reposter's account deletion (§13b step 2) decrements `repost_count` of the posts they reposted. No report on a repost (report the post). Not journaled (volume, like likes).
9. **Limits**: shared bucket `social_write` plus `repost` 30 / hour and 200 / day per user; `x-requires-verified-email: true` (it publishes on the caller's profile).

## Consequences
+ No audience leak by construction and no fan-out job; one table, one counter, one CHECK change.
− Abonnements query becomes a UNION + GROUP BY over followed authors and followed reposters: index `repost (user_id, created_at DESC)` and EXPLAIN on demo seed required (p95 < 150 ms).
− `repost_count` counts reposts the viewer may no longer see (same accepted drift as `like_count`).

## Point to confirm by the user
Only posts with a public audience can be reposted; followers / close-friends posts cannot (recommended; the alternative needs per-viewer double audience checks).
