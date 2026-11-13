# ADR 014 — External catalogue (Deezer) behind `CatalogProvider` and a feature flag (FB-06)

Status: proposed (2 points to confirm by the user, see end) · 2026-11-13 · Lot `p4-contract`, phase 4.

## Context
FB-06: find famous artists through an authorised catalogue. Deezer's public API (no key) gives artist search, artist pages, top tracks and 30 s previews, under its developer terms: previews played from Deezer's own URL only (never stored, proxied or downloadable), visible attribution, non-commercial use. A commercial launch needs a Deezer agreement or the feature switched off. Production is one 1 GB box; Deezer can be slow, rate-limited (≈ 50 requests / 5 s per IP) or down, and answers some errors with HTTP 200 + `{"error":{…}}`.

## Decision
1. **Interface** `App\Catalog\CatalogProvider` (CONVENTIONS §7): `searchArtists(string $q, int $limit): CatalogResult`, `artist(string $externalId): ?CatalogArtist` (with ≤ 10 top tracks), `chartArtists(int $limit): CatalogResult`. Implementations: `DeezerCatalogProvider` (Symfony HttpClient, base `https://api.deezer.com`) and `NullCatalogProvider`. Ids are provider-prefixed opaque strings `dz:<digits>` (pattern `^dz:[0-9]{1,15}$`, validated before any outbound call; the digits are the only user input placed in a Deezer URL path; `q` goes only through the query string, URL-encoded by HttpClient).
2. **Feature flag** env `CATALOG_PROVIDER` ∈ `none` (default, also prod default) | `deezer`. `none` → every `/catalog/*` route answers **404 `not_found`** (route map hidden, as for 405) and `Me.features.catalog = false`; the front hides every catalogue surface from that field, never by probing.
3. **Separate `/catalog/*` routes, not a `/search` section.** Reasons: different object model (no UUID, no audience, no like/playlist/post), different failure mode (an external timeout must never slow or fail `/search`, which has its own p95 target and rate bucket), attribution is per response, and switching the flag off leaves `/search` and its types untouched. Routes: `GET /catalog/artists?q=` (≤ 10, no paging), `GET /catalog/artists/{externalId}` (artist + top tracks), `GET /catalog/charts/artists` (≤ 10, for Découvrir).
4. **Read-only and isolated.** No Wave `Artist`/`Track` row is ever created from catalogue data; no table stores Deezer data; catalogue tracks cannot be liked, added to playlists, posted or clipped; no listen, view or affinity is recorded for previews; they never enter feeds, recommendations, trending or `/search`.
5. **Previews**: `previewUrl` is Deezer's URL, returned as-is and played by the browser directly from Deezer's CDN (`<audio>` without `crossOrigin`, no Web Audio, no download link). No audio byte transits through Wave (no proxy, no X-Accel, no storage). Preview URLs are short-lived signed URLs: the front refetches the artist once on a media error.
6. **Cache** (Symfony pool `cache.catalog` on `cache.adapter.doctrine_dbal`, shared by FPM workers; keys hash the normalised query): search 1 h, chart 1 h, artist metadata 24 h, top tracks (they carry preview URLs) **10 min**. Stampede: `CacheInterface::get()` with a callback (one recompute per key). On provider failure, stale artist metadata may be served up to 24 h with `topTracks: []`.
7. **Outbound limits**: shared limiter `catalog_outbound` 40 requests / 5 s (token bucket, DBAL storage); timeouts connect 2 s, total 3 s; no retry inside a request. Limiter exhausted, timeout, network error, non-2xx, or a 200 body with `error` → **failure**. Failure never yields a 500: list routes answer 200 with `available: false, items: []` (the front omits the section); the artist route answers **503 `catalog_unavailable`** with `Retry-After: 60` when nothing is cached. Inbound: bucket `catalog` 30 / min and 500 / day per user.
8. **Attribution**: every catalogue response carries `attribution {text: "Données et extraits : Deezer", url: "https://www.deezer.com"}`, and each artist/track a `deezerUrl`; the front shows the text next to every catalogue block and the "Écouter sur Deezer" link.
9. **CSP (media-infra)**: add `https://*.dzcdn.net` to `img-src` and `media-src` of `front-static.conf` and `front-dev.conf` (artist pictures `e-cdns-images.dzcdn.net`/`cdn-images.dzcdn.net`, previews `cdnt-preview.dzcdn.net`/`cdns-preview-*.dzcdn.net`); `connect-src` unchanged (the browser never calls `api.deezer.com`). No COEP `require-corp` on the front (it would block the previews). API CSP (`fpm.conf`) unchanged.
10. **Privacy**: search queries reach Deezer from the server, without user identity; playing a preview exposes the listener's IP to Deezer's CDN: one line in the privacy notice (README prod checklist).

## Consequences
+ Switchable and swappable (Spotify/MusicBrainz later = another provider, same routes); no rights risk on stored media; Deezer outages degrade to a missing section.
− Previews live outside the global player's listen accounting and the signed-URL model (by design).
− One DBAL cache table grows with distinct queries (pool prune daily by the scheduler).

## Points to confirm by the user
1. Flag off in production until the commercial question is settled (recommended), on in dev and demo.
2. Catalogue artists only in Découvrir and their own page, never mixed into Wave search results (recommended).
