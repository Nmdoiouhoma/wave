# ADR 010 — Listens counted only against a stream grant

Status: accepted (W-26 F13) · 2026-11-07

## Context
`POST /tracks/{id}/listens` trusted the client-declared `listenedMs` with a window `max(30 s, duration/2)`: a script could
add ~40 plays/h per account and track without ever streaming audio. `play_count` feeds ranking and recommendations (ADR 005).

## Decision
- **Grant**: each successful `GET /tracks/{id}/stream-url` (and post stream-url, phase 3) upserts `listen_grant(user_id, track_id, issued_at)`
  in Postgres (PK on the pair, one row max). A re-fetch inside a session keeps the original `issued_at`; a grant older than
  `duration + 30 min` is renewed. Postgres rather than a cache: the grant is consumed in the same transaction as the listen insert
  and the `play_count` increment, and `DELETE … RETURNING` serializes concurrent reports on the same pair.
- **Count rule** (DATA_MODEL §11): duration rule unchanged ∧ fresh grant (consumed by every report) ∧ `listenedMs ≤ elapsed since issued_at + 2 s`
  ∧ no counted listen of the pair within `max(30 s, duration)` ∧ < 20 counted listens of the pair over a rolling 24 h.
- **Failure is silent**: 200 `{counted: false}`, as for a too-short listen. A 4xx would be an oracle for tuning a script and would
  surface errors on legitimate edge cases (fully buffered track resumed after a long pause, keepalive report after the grant was purged).

## Consequences
+ Inflation bounded to 20/day per account and track and requires real elapsed time per counted play; no new endpoint, no client change.
− One extra upsert per stream-url and one delete per listen report; a daily purge job.
− Rare legitimate plays are not counted (resume of a fully buffered track > 30 min after the grant). Accepted.
