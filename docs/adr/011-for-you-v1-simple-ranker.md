# ADR 011 — For-you v1 (phase 3): SimpleFeedRanker, session cursor, seen posts

Status: accepted · 2026-11-07 · Source: FB-12 (user decision). Superseded in phase 4 by the ADR 005 ranker (W-40), same contract.

## Context
FB-12 wants the "Pour toi" feed in phase 3, before the precomputed signals of ADR 005 (`user_affinity`, `trending_score` jobs, W-40) exist.
It must mix followed accounts, recent popular posts and liked genres, never show a seen or own post, cap 2 posts per author per page, never be empty while another user has a playable post, and be swappable without touching `/feed/for-you` or the client.

## Decision
**Interface**: `App\Feed\FeedRanker` unchanged (CONVENTIONS §7). Phase 3 binds the alias to `SimpleFeedRanker`; W-40 rebinds it to `SqlFeedRanker` and deletes `SimpleFeedRanker`. Weights live in `App\Feed\ForYouWeights` (constants only).

**Seen storage**: no new table. "Seen" ⇔ a `post_view` row exists for (viewer, post) (DATA_MODEL §11), written by `POST /post-views`. Index `(user_id, last_seen_at, post_id)` added for the fallback phase.

**Candidates** (both phases): playable posts (DATA_MODEL §9, which now includes "author not suspended"), `author_id ≠ viewer`, `created_at ≤ now₀` (session start, frozen in the cursor). No age limit in v1 (freshness decays old posts instead).

**Phase `u` (unseen)**: candidates without `post_view` for the viewer, ordered by `(score DESC, id DESC)` with
`score = round(0.35·F + 0.25·G + 0.25·P·R + 0.10·R + 0.05·J, 9)` (numeric, deterministic):
- F = 1 if the viewer follows the author, else 0.
- G = viewer's genre weight for the post's track genre: `n_g / max(n)` where `n_g` = viewer's track likes + post likes on tracks of genre g in the 90 days before now₀ (0 when no likes).
- P = `least(1, ln(1 + 3·like_count + 4·comment_count + view_count) / ln(1001))` (fixed normalization, so a post's P does not depend on the other candidates).
- R = `0.5 ^ (age_hours / 72)`, age measured at now₀.
- J = `abs(hashtext(seed || id::text)) / 2147483647.0`, seed = 8 random hex chars drawn at the first page (stable exploration within a session).

**Phase `s` (seen fallback)**: when phase `u` is exhausted, candidates the viewer has seen with `post_view.last_seen_at < now₀`, ordered by `(last_seen_at ASC, post_id ASC)` (least recently seen first). Posts seen during the session are excluded, so a session never repeats a post; the next session (new now₀) recycles them.

**Page assembly**: scan up to `3·limit + 1` rows of the current phase, keep a row unless its author already has 2 posts in the page, stop at `limit` kept rows. If phase `u` runs dry, continue in phase `s` within the same page (the author cap counts across both). The cursor points at the **last examined row** (rows skipped by the cap before it are dropped for this session). A page may therefore hold fewer than `limit` items while `nextCursor` is not null; `nextCursor: null` = both phases exhausted for this session.

**Cursor**: CursorCodec-signed (CONVENTIONS §6), payload `{"v":1,"r":"simple1","p":"u"|"s","seed":"…","now":"<µs UTC>","k":[<score as string>|"<last_seen_at>","<id>"]}`. Unknown `r` (cursor from another ranker after a deploy), bad phase or tampering → 400 `invalid_cursor`; the client then restarts from the first page.

## Consequences
+ Ships in phase 3 with one SQL query per phase on existing tables; W-40 only swaps the DI alias and keeps W-39's behavioural tests.
+ No existence oracle: the ranker only returns ids passing the playable predicate for the viewer at call time.
− G, F and counters are live: a score can change between pages (likes, follows), so a post may be skipped or repeated within a session; the contract already says clients dedupe by id.
− Full scan of all playable posts per page: fine below ~50k posts; W-40 adds the 30-day window and precomputed signals.
