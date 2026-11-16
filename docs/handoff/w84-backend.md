# W-84 · backend — Catalogue externe (FB-06, ADR 014)

## Fait
- Interface `App\Catalog\CatalogProvider` + `DeezerCatalogProvider` (api.deezer.com, sans clé) + `NullCatalogProvider`.
- Flag `CATALOG_PROVIDER` (none|deezer) : `CatalogFeature` ferme les routes en 404 et pilote `Me.features.catalog`.
- Endpoints : `GET /catalog/artists?q=`, `/catalog/charts/artists`, `/catalog/artists/{externalId}` (requirement `dz:[0-9]{1,15}`).
- Sécurité : `externalId`/`q` validés avant tout appel (id invalide / `../` → 404, 0 appel sortant) ; MockHttpClient en test, jamais Deezer réel.
- Jamais de 500 sur panne : listes → 200 `available:false`+items vides ; artiste → métadonnées périmées (24 h) + `topTracks:[]`, sinon 503 `catalog_unavailable`+`Retry-After`.
- `previewUrl` renvoyé verbatim (aucune URL Wave) ; attribution présente dans chaque réponse.
- Cache pool `cache.catalog` (DBAL prod / array test), purgé par le scheduler existant (`cache:pool:prune`). Pas de table, pas d'audio stocké.
- 3 limiteurs : `catalog_minute_user` (30/min), `catalog_day_user` (500/j), `catalog_outbound` (token_bucket 40). `http_client` activé.
- 24 tests (`tests/Functional/Catalog` + `Me`), un par exigence de sécurité.

## Décisions prises
- Factory `CatalogProviderFactory` : sélection honnête Deezer/Null selon le flag ; gating 404 via `CatalogFeature` (équivaut au masquage de route de l'ADR).
- Échecs et résultats vides jamais mis en cache (getItem/save manuels, pas de callback) ; compromis anti-stampede assumé.

## Contrat d'API modifié ? NON
Implémente le contrat déjà présent dans openapi.yaml (endpoints `/catalog/*`, champ `Me.features.catalog`, `ProblemCode::CatalogUnavailable` = 503). Le check `openapi` de wave-verif est vert → conforme, rien de nouveau à trancher.

## Reste à faire / points ouverts
- Rien de bloquant. En prod `CATALOG_PROVIDER=none` (routes 404) ; le compose dev l'injecte à `deezer`.
- Aucune migration Doctrine (pas de table).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh back` — VERDICT OK (phpstan 0, cs-fixer OK, phpunit 561 tests, media/openapi/audit OK).
Ne jamais lancer un second phpunit en parallèle (base de test partagée → SQLSTATE 25P02).
