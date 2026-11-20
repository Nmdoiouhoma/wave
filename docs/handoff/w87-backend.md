## Fait
- `GET /discover` (`getDiscoverHome`) : `src/Discover/DiscoverQuery.php` (SQL des 6 sections), `State/Provider/DiscoverHomeProvider.php`, `ApiResource/DiscoverHomeView.php` + `DiscoverForYouTrackView.php`.
- Public seul (ADR 012 §6) : morceau public-visible + propriétaire non suspendu ; post « jouable publiquement » = nouveau fragment `TrackAudience::publicPostPlayableSql()` (même règle que `postPlayableSql`, audience réduite à `public`).
- Cache `cache.discover` (cache.app, array en test) : tendances, classement des genres, hashtags 15 min ; nouveautés 5 min (24 candidats, filtrés du propriétaire appelant, requête directe si < 12). Utilisateurs suggérés et Pour toi non cachés.
- Hydratation sans N+1 : tous les morceaux des 3 listes en 1 requête (`findByIdsWithRelations`) + 1 `likedByMe` ; utilisateurs 1 requête + 1 `isFollowedByMe`. 14 requêtes au total, constant (testé). Les ids cachés sont revérifiés au chargement (masqué / suspendu disparaît sans attendre le cache, testé).
- Limiteur `discover_user` 60/min (contrat `x-rate-limit`), mappé dans `RateLimitListener`.
- `tests/Functional/Discover/DiscoverHomeTest.php` (10 tests) : 401, 6 sections toujours présentes ; chaque exclusion (soi pour nouveautés/suggestions/Pour toi, suivi, suspendu, morceau restreint/privé/en traitement/masqué, post masqué/non prêt/clip interdit, hashtag vide) ; chaque ordre (score puis id, date puis id, affinité puis nombre puis nom, abonnés communs puis abonnés puis id, récents 7 j puis post_count puis nom, poids puis tendance puis id + 3 par artiste).

## Décisions prises
- Pour toi : poids = max(score artiste, score genre) ; raison = celle du plus grand score, **artiste en cas d'égalité**. `reasonLabel` = nom de l'artiste ou nom français du genre.
- Genres : les 45 sont classés (un genre sans morceau peut compléter les 12) ; affinités hors genres connus ignorées.
- Hashtags : listés seulement avec ≥ 1 post jouable publiquement (toutes dates) ; `postCount` = compteur global `hashtag.post_count` (comme `/search`).
- Tendances : un morceau public de l'appelant n'est pas exclu (le contrat ne l'exclut que des nouveautés et de Pour toi).

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
- EXPLAIN sur le seed (43 morceaux, 17 posts) : seq scans partout, normal à cette taille. Avec `enable_seqscan=off` : tendances = `idx_track_trending` (Index Scan, tri évité) ; suggestions = `idx_follow_follower_created` + PK ; hashtags = index post_hashtag/post ; Pour toi = PK `user_affinity`, `track_like_pkey`, index listen (user, track). Nouveautés : pas d'index `created_at` global → filtre via l'index partiel tendances puis tri. Requête cachée 5 min : **pas de migration** ; si besoin plus tard, index partiel `(created_at DESC, id DESC) WHERE status='ready' AND visibility='public' AND moderation='visible'`.
- Suggestions : balaye tous les utilisateurs candidats (tri par `followers_count`) — OK à l'échelle actuelle, à revoir au-delà de ~10 k comptes.

## Commande pour vérifier
`make test c="--no-progress tests/Functional/Discover"` puis `bash .claude/skills/wave-verif/verif.sh back`
