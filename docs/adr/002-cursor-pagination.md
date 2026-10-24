# ADR 002 — Cursor (keyset) pagination

Status: accepted · 2026-10-24

## Context
Feeds and lists are append-heavy and infinite-scrolled; OFFSET skips/duplicates items on insert and degrades linearly. API Platform's default is page-based with Hydra.

## Decision
- All lists: `?cursor=&limit=` → `{items, nextCursor}`; `nextCursor: null` ends the list; no totals (counters live on parent resources).
- Cursor = `base64url(json payload) . base64url(HMAC-SHA256(payload, APP_SECRET)[0..16])`; payload `{"v":1,"k":[<sort values of last item>, "<id>"]}`. Invalid/tampered → 400 `invalid_cursor`.
- Query: `WHERE (sort, id) < (:s, :id) ORDER BY sort DESC, id DESC LIMIT :limit + 1`. Every list has an index matching its order (DATA_MODEL). Timestamps encoded with microseconds.
- API Platform pagination disabled (`pagination_enabled: false`); providers return a `CursorPage` DTO.
- Documented exceptions: search (cursor carries an offset, capped at 200 results — relevance order has no stable keyset), for-you (ranker-owned cursor, ADR 005), playlist items (keyset on `position`, which only the owner can change).

## Consequences
+ Stable infinite scroll, O(log n) per page, identical for web and React Native.
− No "page 7 of 12" or total counts; clients cannot jump. Accepted for a feed product.
