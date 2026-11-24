# fb-1124 — backend (retours de test phase 4)

## Fait
- **1. Compteur du hashtag** : `HashtagRepository::findVisibleByName` renvoie `postCount` = nombre de posts du hashtag jouables par le spectateur (`TrackAudience::postPlayableSql`, le même prédicat que `/hashtags/{name}/posts`), via une seule requête groupée (JOIN + `count(*)`, plus de `post_count` stocké). Même fuite dans la recherche (`type=hashtags`) : nouvelle méthode `findVisibleByIdsOrdered($ids, $viewerId)` (une requête pour la page, pas de N+1), utilisée par `SearchResultsProvider` ; l'ancienne `findByIdsOrdered` est supprimée (plus aucun appelant).
- **2. Tableau de bord** : aucun des deux fournisseurs n'avait de bug de date, de fuseau ou de clés. C'est la **série** qui se trompe, à cause de `make seed` : le seed crée comptes, morceaux, posts et commentaires sans passer par les processeurs (donc sans journal) et les antidate, et `removeDemo` supprime les comptes en SQL en laissant leurs événements (acteur mis à NULL). Mesuré en base dev : les 56 `content.track_created` du 24/11 ont acteur et cible supprimés ; aucun événement `post_created`/`comment_created`. Correctif : `src/Command/Seed/SeedJournal.php` (`forgetDemo` avant la suppression = règle §13b, plus les événements de contenu orphelins ; `backfill` après `stagger` = un événement par compte, morceau, post et commentaire de démo, daté comme le contenu, id v7 de cette date), branché dans `SeedDevCommand`.
- Tests : `SearchPhaseFourTest::testGetHashtag` (compteur = fil, pour 3 spectateurs, et la recherche aussi) ; nouveau `tests/Functional/Admin/AdminDashboardConsistencyTest.php` (carte et case du jour de la série égales sur les mêmes données : seed simulé, événement orphelin, commentaire réel par l'API).
- **3. Supervision** : conforme. DATA_MODEL §16 (note W-88) fixe `high → critique`, `medium → alerte`, `low → attention`, et FB-34 / openapi : `global` = le pire signal. Rien changé.

## Décisions prises
- Le journal reste la source de la série (contrat W-82 « monitoring, not accounting ») : on répare les données du seed plutôt que de faire lire les tables à la série.
- Le seed journalise aussi `account.registered` (sinon la série « inscriptions » aurait le même écart que « contenus »).

## Contrat d'API modifié ? (oui/non + détail)
Non dans la forme ; **oui dans le sens** de `Hashtag.postCount` (`GET /hashtags/{name}`, `/search?type=hashtags`) : c'est maintenant un nombre propre au spectateur. Proposition pour l'architecte (openapi.yaml hors de mon périmètre), sur `Hashtag.postCount` : « Posts carrying the hashtag that the caller can play (same rule as `/hashtags/{name}/posts`); 0 is never returned. In `trendingHashtags` (discover): the stored global count. »

## Reste à faire / points ouverts
- `/discover` `trendingHashtags` renvoie toujours le `post_count` global stocké (cache global, trié dessus selon le contrat W-87) : même fuite, plus faible (seul un hashtag ayant ≥ 1 post jouable publiquement est listé). Proposition : compter les posts jouables publiquement dans la CTE `live` existante (décision de contrat, à trancher par l'architecte).
- La base dev garde ses événements orphelins jusqu'au prochain `make seed` (pas lancé, comme demandé) : après celui-ci, la série et la carte concordent.

## Commande pour vérifier
`make test c="--no-progress --filter '(AdminDashboardConsistencyTest|SearchPhaseFourTest|AdminDashboardSeriesTest)'" 2>&1 | tail -20` puis `bash .claude/skills/wave-verif/verif.sh back`.
