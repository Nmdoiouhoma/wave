# fb-1109 — backend (FB-13, W-29a)

## Fait
- FB-13 : `AffinityTrigger::touch()` best effort, appelé par `LikeProcessor` et `ListenRecorder` après le commit ;
  dispatch dans sa propre transaction (point de sauvegarde si une transaction est ouverte), échec = rollback seul +
  warning `Affinity recompute request dropped`. Tests `…WhenTheAffinityLockFails` (Like/Listen), rouges avant (500).
- W-29a : `GET /search?type=tracks` dans `PostgresSearchProvider` (CTE de normalisation et échappement LIKE F10
  partagés avec users) ; filtre prêt ∧ visible ∧ (public ∨ à soi) ∧ propriétaire non suspendu ; correspondance
  titre/nom d'artiste par préfixe (aussi par mot) ou trigramme ; classement à soi > liké > titre exact > préfixe >
  similarité > playCount > id. `SearchResultsProvider` accepte users|tracks (autre → 422), curseur
  `[type, q, offset]` (autre type ou q → 400 `invalid_cursor`). Sortie `{"tracks": TrackPage}` via la nouvelle
  classe `TrackSearchResultsView` (sections absentes omises sans toucher `skip_null_values`).
- Tests : `tests/Functional/Search/TrackSearchTest.php` (visibilité cas par cas, correspondance, classement,
  drapeaux sans N+1, pagination, curseur lié dans les 2 sens) ; `SocialSecurityTest::testTrackSearchNeverLeaksInvisibleTracks`
  (privé/masqué/suspendu absents même likés avant et sur titre exact, métacaractères, NUL/UTF-8 invalide).
  `UserSearchTest` : le 422 de type non pris en charge passe de `tracks` à `artists`.

## Décisions prises
- FB-13 : une demande de recalcul perdue (warning) plutôt qu'un like/une écoute en erreur ; `RecomputeAffinityAll` rattrape.
- W-29a : aucune migration (index trigrammes titre et nom d'artiste déjà présents depuis la base initiale).

## Contrat d'API modifié ? (oui/non + détail)
- Non : implémentation du contrat figé en 73c615b.

## Reste à faire / points ouverts
- CONVENTIONS §8 (doc) : préciser « déclencheur d'affinité après commit, best effort ».
- Piège : `ConcurrencySecurityTest` lance des noyaux test sans débogage qui lisent un cache périmé quand un constructeur
  change (500 partout) → `docker compose exec php php bin/console cache:clear --env=test --no-debug`.

## Commande pour vérifier
- `make test c="--no-progress --filter 'TrackSearchTest|UserSearchTest|SocialSecurityTest|LikeTest|ListenTest'" 2>&1 | tail -5`
- Suite complète : 341 tests OK (62 ignorés) ; PHPStan propre ; cs-fixer appliqué.
