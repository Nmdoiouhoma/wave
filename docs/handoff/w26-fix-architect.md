## Fait
- Contrat W-26 F13-F16 : `docs/openapi.yaml` (règles globales, users, likes, stream-url, listens, playlists, `ProblemCode`), `docs/DATA_MODEL.md` §7, §8, §11 (+ table `listen_grant`), §13b, nouvel ADR `docs/adr/010-listen-grant.md`.
## Décisions prises
- F13 : écoute comptée seulement avec un `listen_grant` (user, track) frais (`issued_at ≥ now − (durée + 30 min)`), consommé par tout rapport ; `listenedMs ≤ écoulé depuis issued_at + 2 s` ; anti-rejeu `max(30 s, durée)` ; plafond 20 écoutes comptées / 24 h glissantes par (user, track). Échec → 200 `counted: false`, jamais de 4xx. État en Postgres (ADR 010).
- F15 : 200 playlists max par utilisateur → 409 `playlist_limit_reached` (nouveau code) ; bucket `playlist_write` 600/h (create + add item) ; non-propriétaire : items à titre invisible omis (pas de `trackId`), propriétaire inchangé ; `trackIds` non-liste → 422 `validation_failed`.
- F16 : suspendu → 404 sur toutes les routes `/users/{username}*` sauf `DELETE …/follow` ; suspendus omis des listes abonnés/abonnements ; compteurs inchangés jusqu'à la phase 5.
- F14 (corrigé après retour backend, validé lead) : unlike supprime le like existant quelle que soit la visibilité ; sans like, cible invisible → 404 comme inexistante (pas d'oracle, T17). Unfollow : toute cible existante, suspendue comprise.
- `listen_grant` garde les index Doctrine sur `user_id` et `track_id` (celui sur `track_id` sert la cascade), noté en DATA_MODEL §11.
## Contrat d'API modifié ? (oui/non + détail)
- Oui : code `playlist_limit_reached` (409) ; 409/429 sur `createPlaylist`, 429 sur `addPlaylistItem`, 422 sur `reorderPlaylist` ; 404 pour suspendu ; items omis pour les non-propriétaires. Aucun schéma modifié (types front inchangés hormis l'enum `ProblemCode`).
## Reste à faire / points ouverts
Backend, à implémenter :
1. Migration `listen_grant` (PK user_id+track_id, FK CASCADE x2, `issued_at`) ; upsert dans le provider de `GET /tracks/{id}/stream-url` (SQL exact en DATA_MODEL §11).
2. `ListenRules` : fenêtre = `max(MIN_MS, referenceMs)` ; ajouter `elapsedOk(listenedMs, elapsedMs)` (+2 000) et `DAILY_CAP = 20`. Processeur de listen : dans la transaction, `DELETE FROM listen_grant … RETURNING issued_at` (absent ou périmé → non compté), puis durée, écoulé, anti-rejeu, plafond (COUNT LIMIT 20 sur 24 h).
3. Job planifié quotidien : purge `listen_grant` > 1 jour.
4. `ProblemCode::PlaylistLimitReached` (409) ; limite 200 au `createPlaylist` sous `FOR UPDATE` de l'app_user ; rate limiter `playlist_write` 600/h sur create + add item.
5. `PlaylistItemsProvider` : pour un non-propriétaire, filtrer en SQL les items dont le titre est invisible.
6. `PlaylistItemProcessor` (reorder) : `trackIds` doit être `array_is_list` d'uuid, sinon 422 `validation_failed`.
7. `UserLookup::byUsername` → 404 si `suspended_at` non nul (variante sans ce filtre pour l'unfollow) ; `FollowRepository::page` : jointure `app_user` avec `suspended_at IS NULL`.
8. F14 : déjà implémenté par le backend selon `w26-fix-backend.md`.
- Front : enum `ProblemCode` à régénérer ; message pour `playlist_limit_reached`. SECURITY.md T20 à mettre à jour par qa après correctif.
## Commande pour vérifier
`ruby -ryaml -e 'YAML.load_file("docs/openapi.yaml")'` · `grep -n "playlist_limit_reached\|listen_grant\|Suspended" docs/openapi.yaml docs/DATA_MODEL.md`
