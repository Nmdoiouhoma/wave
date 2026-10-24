# ADR 003 — Object storage abstraction

Status: accepted · 2026-10-24

## Context
MinIO locally, S3-compatible in prod; media code (media-infra) and API code (backend) both touch objects; audio must stay private, images may be public.

## Decision
- `App\Storage\ObjectStorage` (signatures in CONVENTIONS §7) is the only way to touch objects. Implementation `FlysystemObjectStorage` wraps two Flysystem `AwsS3V3Adapter` filesystems (`wave-private`, `wave-public`) selected by `StorageArea`.
- Two buckets, not prefixes: different policies (private: nginx-internal only; public: anonymous read through nginx `/media/`), no risk of one misconfigured prefix exposing audio.
- Keys are deterministic per entity and **versioned with a uuid** for anything replaceable (images), so public objects are immutable and cacheable for a year (`Cache-Control: public, max-age=31536000, immutable`). Layout in DATA_MODEL §14.
- Uploads go client → PHP (size + declared type pre-check) → `quarantine/` in the private bucket → worker validates with ffprobe → processed key. Nothing in quarantine is ever served.
- Deletions are asynchronous (`PurgeObjects`) and idempotent; DB is the source of truth, orphan objects are tolerated and swept by `CleanupQuarantine`.
- Tests use an in-memory Flysystem adapter behind the same interface.

## Consequences
+ Swap MinIO → S3/R2 by env vars only. Media code is testable without network.
− Upload goes through PHP (no presigned direct upload); fine at ≤ 30 MB, revisit for larger files (presigned PUT to quarantine + `POST /tracks/{id}/complete`).
