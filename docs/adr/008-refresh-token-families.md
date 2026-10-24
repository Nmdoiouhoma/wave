# ADR 008 — Refresh-token families and reuse detection

Status: accepted (security finding F2) · 2026-10-24

## Context
D3 rotates refresh tokens on every use. Plain rotation deletes the old token, so a stolen token used after the victim (or before) is indistinguishable from an unknown one, and the thief's branch survives. OAuth 2.0 Security BCP (RFC 9700 §4.14.2) recommends reuse detection with family revocation.

## Decision
- Every login/register starts a **family** (`refresh_token.family_id`, uuid). Rotation creates a child in the same family and marks the parent `rotated_at`; rotated rows are kept until their `valid` date.
- Presenting a token whose `rotated_at` is set = reuse → delete every token of the family (all descendants) and answer 401 `token_reused`; web cookie cleared. Both the attacker and the legitimate client must log in again.
- The access JWT carries `sid` = family_id, so endpoints without the refresh cookie (cookie path is `/api/v1/auth`) know the current session.
- `POST /me/password`: deletes all families of the user **except** `sid`, rotates the current family and returns a fresh session (access + refresh, cookie or body). Password-reset confirm, suspension and account deletion delete **all** families (the requester is not authenticated, or the account is compromised/gone).
- Logout deletes the current family.
- Concurrency: two tabs refreshing at once look like reuse. The web client keeps refresh single-flight across tabs (`BroadcastChannel` / `navigator.locks`); no grace window server-side.

## Consequences
+ Stolen-token replay is detected and kills the session on both sides.
− Table grows by one row per refresh until expiry (purged daily); client bugs that double-refresh log users out (covered by front tests).
