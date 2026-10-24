# Wave — security threat model v1 (phase 1)

Owner: qa-security · 2026-10-24 · Scope: auth, accounts, artists, tracks, uploads, streaming, infra (W-16).
Tests: `backend/tests/Security/` (php container; `MediaSecurityTest` in the worker image, `NginxEdgeTest` needs the stack).

## Assets
| Asset | Why it matters |
|---|---|
| Credentials, refresh tokens, account tokens (verify/reset) | Account takeover |
| Private / processing / hidden tracks (audio + metadata) | Artists' unreleased work; existence must not leak (404, not 403) |
| JWT private key, `STREAM_SIGNING_KEY`, `APP_SECRET` (cursors), MinIO root/app keys | Forge sessions, stream URLs, read every object |
| Email addresses (`/me` only) | Enumeration, spam, phishing |
| Worker host (ffmpeg on untrusted files) | RCE / local file read / SSRF pivot |

## Actors
Anonymous internet client · registered **unverified** user · verified user · moderator · thief holding a leaked token/URL · malicious uploader · compromised container on the `internal` network · supply chain (images, Composer, npm).

## Trust boundaries
1. Browser/mobile → nginx (only published port). 2. nginx → PHP-FPM (front controller only) and → MinIO (X-Accel `/_protected/`, `/media/`).
3. PHP → Postgres / MinIO (app credentials, 2 buckets). 4. Messenger → worker → ffmpeg/ffprobe on quarantined bytes. 5. Worker → SMTP.

## Threats, mitigations, status
| # | Threat | Mitigation in place | Status |
|---|---|---|---|
| T1 | Credential stuffing / brute force | Limits per IP+email, per IP, register per IP, constant-cost unknown-email login | OK when client IP is right; **open: XFF spoofable by peers in `TRUSTED_PROXIES` (F1)** |
| T2 | Account enumeration | Reset: identical 202, lookup in worker; login: same 401 body. Register 409 accepted (ADR 007) | OK (tested, timing within noise) |
| T3 | Account-token theft / guessing | 256-bit random, SHA-256 stored, single use (atomic UPDATE), purpose-bound, TTL 24 h / 1 h, newest voids older, 10/min/IP | OK (tested) |
| T4 | Refresh token theft | HttpOnly + SameSite=Strict + Path=/api/v1/auth cookie (web), rotation, revoke-all on password change/reset/suspension | **Partial: no reuse detection (F2); `Secure` depends on an env flag (F4)** |
| T5 | JWT forgery (`alg:none`, HS/RS confusion, expired, roles claim) | Lexik RS256 with fixed signer, `exp` required, header-only extractor, user + roles reloaded from DB | OK (tested) |
| T6 | Verified-email gate bypass | `security:` expression on all 6 gated phase-1 operations; no method override | OK (tested, incl. path/method variants) |
| T7 | IDOR / visibility leaks | Voters + lookups: invisible → 404 everywhere (read, mutate, stream-url, lists), visible-not-owner → 403; artistId ownership on upload/move | OK (tested) |
| T8 | Mass assignment | `JsonInputDecoder`: unknown property → 422, explicit DTOs, multipart whitelist | OK (tested) |
| T9 | Stream URL abuse | HMAC-SHA256 with dedicated key, `hash_equals`, canonical b64, exp 5 min, visibility/suspension/deletion re-checked on every Range request, `internal` location | OK (tested). A leaked URL is a 5-min bearer (accepted, ADR 001) |
| T10 | Direct object access | `/_protected/` internal, `/media/` query strings dropped, private bucket anonymous read limited to `audio/*` from the compose subnet | **Partial: any container on `internal` can read all private audio anonymously (F5)** |
| T11 | Hostile uploads (spoofed MIME, polyglot, bombs, traversal) | Size/MIME/extension pre-filter (415/413), random object keys (filename never used), quarantine bucket, ffprobe content sniffing, codec whitelist, 50 Mpx cap, WebP re-encode strips metadata | OK (tested) |
| T12 | ffmpeg as a pivot (HLS/concat/SDP, `file://`, URLs, argument injection) | `-protocol_whitelist file`, `-format_whitelist`, `file:` prefix, argv (no shell), timeouts, worker without published port, read-only FS, 1 GB / 512 MB tmpfs | OK (tested). Hardening ideas: `-codec_whitelist`, explicit `-enable_drefs 0` |
| T13 | PHP runtime (PHP2Shell, firewall CVE) | PHP 8.4.27 pinned by digest, Symfony 7.4.21, FPM `disable_functions`, upload_progress off, non-root, cap_drop ALL | OK; **`conflict` block of ADR 006 missing (F7)** |
| T14 | Secrets in git | Only dev placeholders in `backend/.env`; root `.env` and `*.pem` ignored; history clean | OK; **no prod guard against dev defaults (F6)** |
| T15 | XSS / open redirect / token leakage (front) | React only, no `dangerouslySetInnerHTML`; access JWT in memory; `safeNext` same-app paths; token pages strip `?token=` + `no-referrer` (meta and nginx) | OK. Prod CSP allows inline styles (low) |
| T16 | Supply chain | PHP digest-pinned; others tag-pinned; `make audit` | **MinIO = community fork `pgsty/minio`, tag-only (F8)** |

## Findings (see W-16 report for file:line and fixes)
F1 X-Forwarded-For trusted from `172.16.0.0/12` (medium/high depending on deployment) · F2 refresh reuse not detected (medium, contract) · F3 rate-limiter rows never pruned (low) · F4 `REFRESH_COOKIE_SECURE` not tied to prod, no TLS/HSTS in the prod-shaped stack (medium) · F5 anonymous private-bucket read from every internal container (medium) · F6 dev-default secrets accepted at boot (low) · F7 ADR 006 Composer `conflict` missing (low) · F8 MinIO image provenance (medium) · F9 access JWT survives password reset ≤ 15 min (low, accepted).

## Out of scope v1 / next
Phase 2+ (playlists, likes, posts, comments, reports, moderation endpoints), account deletion, mobile client storage, DoS beyond rate limits, TLS termination design.
