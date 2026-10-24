# ADR 004 — Artist as a real entity

Status: accepted (D6) · 2026-10-24

## Context
A user may publish under several names (solo project, band); listeners browse artists, not accounts. Using `User` as the artist conflates identity, login and catalogue.

## Decision
- `Artist` (slug, name, bio, avatar, owner) owned by exactly one `User`; a user manages up to 10 artists. Tracks belong to an Artist; `track.owner_id` is denormalized for cheap authorization and listing.
- First upload without `artistId` auto-creates an artist named after the user's `displayName`, so the simple path needs no extra step. With several artists, `artistId` is required (422 `artist_required`).
- Slug immutable (stable URLs `/artists/{slug}`), generated with collision suffixes.
- **Follow targets users only** in v1 (feed = posts by users; one graph). Artist taste is learned through `user_affinity(kind=artist)`. An `artist_follow` table can be added later without changing existing endpoints.
- Artist with tracks cannot be deleted (409); tracks can be moved between one's own artists.
- No shared/multi-owner artists, no featuring credits in v1.

## Consequences
+ Clean artist pages and search; recommendations can key on artist independently of uploader.
− Two-step authorization (track → artist → owner) mitigated by `track.owner_id`; must be kept in sync on artist change (only own artists allowed, so owner never changes).
