# ADR 005 — Recommendation architecture

Status: accepted · 2026-10-24

## Context
For-you needs to be decent at launch with tiny data, cheap on PostgreSQL only, and replaceable by collaborative filtering or embeddings later without touching API or client.

## Decision
`FeedRanker` interface (CONVENTIONS §7) returns ranked post ids; providers hydrate. v1 = `SqlFeedRanker`, one SQL query.

**Signals (precomputed)**
- `user_affinity` (job `RecomputeAffinity`): over the last 90 days, weights track like 3, counted player listen 1, post like 2, counted feed listen 0.5, completed post view 0.5; each × `0.5^(age_days/30)`; summed per genre and per artist of the involved track; normalized per kind (max = 1); top 20 kept.
- `trending_score` (job `RecomputeTrending`, every 15 min): track = Σ(listen 1, like 3) × `0.5^(age_h/48)` over 7 days; post = Σ(view 1, completed view +1, like 3, comment 4) × `0.5^(age_h/24)` over 3 days.

**Score** for candidate post p (playable, created ≤ 30 days, not own, not in `post_view` for viewer):
`0.30·G + 0.20·A + 0.20·F + 0.30·T·R + 0.05·J`
- G, A = viewer affinity for p's track genre / artist (0 if absent)
- F = 1 if viewer follows p.author; 0.5 if ≥ 1 followed user liked p in the last 7 days ("followed accounts' popular items"); else 0
- T = `ln(1+trending)/ln(1+max_trending)`; R = `0.5^(age_h/72)` freshness
- J = deterministic jitter `hashtext(seed || p.id)` mapped to [0,1], seed per session (exploration, stable within the session)

**Paging**: cursor `{phase, seed, now, lastScore, lastId}`; `now` frozen at first page so scores are deterministic; keyset on `(score, id)`. Fetch `3×limit`, keep max 2 per author, return `limit`. Phase 2 (unseen exhausted): seen playable posts by `trending_score`. Cold start: G = A = F = 0 → trend + freshness + jitter.

**Evolution path** (same interface, chosen by DI alias):
- v2 `ItemCfFeedRanker`: nightly item–item co-occurrence from `post_like`, `listen`, completed `post_view` into `item_similarity(item_a, item_b, score)`; candidates = neighbours of the viewer's recent positives, blended with v1 score.
- v3 embeddings: `pgvector`, audio embeddings computed by the media worker at `ProcessTrack`, user vector = decayed mean of positives; ANN candidates then re-rank.
- `post_view` is the impression log for offline evaluation (hit rate of liked/completed in top-k); add it before switching rankers.

## Consequences
+ Works on day one with seed data; one query, explainable weights (constants in one class, unit-tested).
− Full scan of 30-day candidates per request: fine below ~100k posts; v2 needs a candidate-generation stage first.
