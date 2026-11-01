# W-26 — revue qa-security phase 2 (playlists, follow, likes, écoutes, recherche, front)

## Fait
- Tests ajoutés : `backend/tests/Security/SocialSecurityTest.php` (IDOR, entrées hostiles, recherche, fuites de champs) et `ConcurrencySecurityTest.php` + `Support/concurrent_request.php` (vraies requêtes parallèles en processus séparés). 3 échecs volontaires = F10, F11, F12.
- Vérifié OK : playlist invisible → 404 identique sur les 9 routes, sans fuite du nom ; playlist publique d'autrui → 403 en écriture ; titres privés/en traitement/masqués d'autrui → 404 (ajout, like, écoute) ; 20 likes concurrents = 20, 20 likes du même = 1, follows 16/1, 10 ajouts contigus, 10 écoutes = 1 ; ni email ni rôles dans les payloads phase 2 ; recherche exclut les suspendus ; 429 écoutes à 121/min. Front : aucun `dangerouslySetInnerHTML`, rendu React échappé, aucun secret ni MSW dans `dist/`.
- `docs/SECURITY.md` : T17-T21, F10-F16.

## Findings
- **MOYEN F10 (backend)** `src/Search/PostgresSearchProvider.php:31,35` : `addcslashes` avant `wave_unaccent`, qui change `％ ＿ ﹪` en `% _` → `GET /api/v1/search?type=users&q=％％` renvoie tout le monde. Échapper après unaccent (en SQL) ou unaccent en PHP avant l'échappement.
- **MOYEN F11 (backend)** `src/Repository/PlaylistRepository.php:221-235` : `detachTrackEverywhere()` décale les positions sans `SELECT … FOR UPDATE` des playlists touchées (ordre par id) → trou [0,2] si un ajout est concurrent, puis l'ajout suivant viole l'unicité de position (500 au commit). Test déterministe.
- **MOYEN F12 (backend, systémique)** `JsonInputDecoder` / query strings : `\u0000` tronqué par pdo_pgsql (nom `"a\u0000b"` stocké `"a"`, `"\u0000x"` → nom vide malgré NotBlank), NUL dans description → 500 ; `q=ab%FF%FE` → 500 (SQLSTATE 22021). Rejeter NUL et UTF-8 invalide en 422/400.
- **MOYEN F13 (architect, contrat)** `src/Listen/ListenRules.php` : fenêtre `max(30 s, durée/2)` et `listenedMs` déclaré par le client, sans lien avec une stream-url émise → ~40 écoutes/h par compte et par titre de 3 min. Proposer : stream-url émise récemment pour (user, track), fenêtre = durée entière, plafond journalier par (user, track).
- **BAS F14** `src/State/Processor/LikeProcessor.php:41-43` : unlike/unfollow d'une cible devenue invisible → 404, compteur gelé (idem follow). Autoriser la suppression de son propre lien sans contrôle de visibilité.
- **BAS F15** pas de limite sur `createPlaylist` / `addPlaylistItem` (stockage) ; `PlaylistItemsProvider.php:68-73` expose `trackId` d'un titre invisible ; `PlaylistItemProcessor.php:74` accepte un objet JSON `{"a": id}` comme `trackIds` (204).
- **BAS F16 (décision architect)** `FollowListProvider.php:45-53`, `FollowRepository::page` (L88-111), `UserLookup::byUsername` : comptes suspendus listés et joignables (profil, follow). Recommandation : filtrer `suspended_at IS NULL` dans les listes (jointure app_user) comme la recherche ; compteurs inchangés jusqu'à la modération phase 5 ; profil/follow d'un suspendu → 404 = changement de contrat à trancher.

## Décisions prises
- Aucune décision de contrat ; F13 et F16 sont renvoyés à l'architecte.

## Contrat d'API modifié ? (oui/non + détail)
- Non.

## Reste à faire / points ouverts
- backend : F10, F11, F12, F14, F15 ; architect : F13, F16. `ConcurrencySecurityTest` est proche des 512 MiB du conteneur php (ajouts réduits à 10 workers, exit 9 à 20) : risque de flakiness.

## Commande pour vérifier
- `make test c="--no-progress tests/Security" 2>&1 | tail -40` → 120 tests, 3 échecs attendus (F10, F11, F12).
