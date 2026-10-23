# Wave — Brief (source of truth, validated 2026-10-23)

Social music web app: Spotify-like library + TikTok-like vertical feed of short audio posts.
API-first: a React Native client will consume the same API later without changes.

## Validated decisions

| # | Decision |
|---|---|
| D1 | Symfony **7.4 LTS, >= 7.4.13** (CVE-2026-48489 firewall bypass). PHP **>= 8.4.27** pinned exactly in Docker (PHP2Shell CVE-2026-109618/109619). Never a floating `php:8.4-fpm` tag. |
| D2 | Audio access via **signed URL (HMAC, 5 min TTL)** from `GET /api/v1/tracks/{id}/stream-url`; PHP checks access, then `X-Accel-Redirect` to MinIO; nginx serves Range + caches (slice module). |
| D3 | Refresh token: **httpOnly cookie on web**, **JSON body for mobile** (gesdinet, rotation). Access JWT 15 min. |
| D4 | Limits: track upload MP3/AAC **<= 30 MB, <= 10 min**; post clip **15–60 s**; post visual image <= 5 MB (jpg/png/webp) or video MP4 <= 15 MB, <= 60 s, muted. |
| D5 | A post may use **another user's track** if it is public, ready, and its owner left `allowClips = true` (default true). |
| D6 | **Artist is a real entity**: own page (name, slug, bio, avatar), managed by one owner User; a user can manage several artists. First upload auto-creates an artist named after the user's displayName. Tracks belong to an Artist (and transitively an owner). |
| D7 | Git repo, **private GitHub remote** (to be added once `gh` is authenticated). Commits authored by the user only, no AI trailer. One commit per feature. |

## Architecture constraints

- All endpoints under `/api/v1`, plain JSON (no Hydra), RFC 7807 errors, **cursor pagination**.
- UUID v7 ids. Denormalized counters updated in the same transaction.
- `ObjectStorage` interface (Flysystem S3 adapter, MinIO locally).
- `FeedRanker` interface (v1 SQL scoring: genre/artist affinity, followed accounts' popular items, freshness-weighted trend) so CF/embeddings can replace it later.
- `SearchProvider` interface (v1 PostgreSQL FTS + unaccent + pg_trgm).
- Uploads quarantined, validated by **ffprobe** in the worker (never trust client MIME), loudnorm -14 LUFS, AAC 160k `.m4a` faststart. Status `processing/ready/failed`.
- Moderation: `Report` entity, auto-hide after 5 distinct reports, `ROLE_MODERATOR` queue.
- Rate limiting: login, register, refresh, uploads, comments, reports.
- PHP-FPM hardening: `php_admin_value[session.upload_progress.enabled]=0`, `post_max_size=32M`, `upload_max_filesize=30M`; nginx `client_max_body_size` 32M on upload routes, 1M elsewhere; container memory limits; worker not network-exposed.
- `make audit`: composer audit + npm audit + fail if PHP < 8.4.27.
- Seeds: **generated audio only** (FFmpeg synthesis), no copyrighted content.
- Code and comments in English; README in French.

## Stack

Backend: Symfony 7.4, API Platform 4, PostgreSQL 16, Doctrine, LexikJWT, gesdinet refresh, Messenger (Doctrine transport), Scheduler, PHPUnit, PHPStan level 8.
Frontend: React 19 + TS + Vite, React Router, TanStack Query, Zustand (player), Tailwind, dnd-kit, Vitest, ESLint + Prettier. Mobile-first.
Infra: Docker Compose (php, nginx, postgres, minio, worker, front), `.env`, Makefile.

## Phases

0 Architecture · 1 Docker, auth, profiles, upload, streaming, global player · 2 Playlists, follow, likes, listens · 3 Posts, vertical feed, comments, reports · 4 Recommendations v1, search, demo seed · 5 Tests, security hardening, moderation UI, README + prod checklist.

## Team & file ownership

| Agent | Owns |
|---|---|
| architect | `docs/` (except SECURITY.md); arbitrates every API contract change |
| backend | `backend/` except `src/Media/`, `src/DataFixtures/`, `tests/Security/`; sole owner of `composer.json` |
| frontend | `frontend/` |
| media-infra | `compose.yaml`, `Makefile`, `infra/`, `.env.example`, `backend/src/Media/`, `backend/src/DataFixtures/` |
| qa-security | `backend/tests/Security/`, `docs/SECURITY.md`; reviews every batch, reports findings, owners fix |

Agents cannot message each other: the lead relays every question and contract change.
