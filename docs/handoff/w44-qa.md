## Fait
Revue qa/sécurité phase 4 (`473ddfc..HEAD` : W-40/41/80/82/84/87/88 + `26ab571`, ADR 014/015). Nouveau test `backend/tests/Security/PhaseFourSecurityTest.php` : 10 tests, 8 verts, 2 échecs voulus (F32, F33).
Couvert et sain : repost d'un post restreint par un inconnu = mêmes octets qu'un id inconnu (PUT/DELETE) ; changement d'audience, piste masquée, désabonnement → repost retiré du fil et de `/users/{u}/reposts` ; recherche (7 entrées hostiles : opérateurs tsquery, `%_` ×50, 50 mots, pleine chasse, NUL, 100 car. × 6 types) jamais de 500 ; catalogue : 7 id malformés (`%0A`, chiffres pleine chasse, 16 chiffres, `/..`, `?`) sans appel sortant, `q` injecté (`&limit=`, `#`, CRLF, `/../`, `@`) reste une valeur encodée vers `api.deezer.com/search/artist`, limit bornée, 7 corps fournisseur malformés → 200/404/503 jamais 500, flag off → 404 sur les 3 routes même pour un admin, 0 appel ; séries admin : agrégats seuls (ni e-mail, ni id, ni pseudo).
Mesures sur le seed dev (33 utilisateurs, 300 posts, 152 reposts) : recherche ≤ 6 ms par type y compris 100 car. et tsquery de 50 lexèmes ; fil abonnements 2,5 ms (+5,6 ms de planification), Pour toi 4,1 ms. Pas d'audio Deezer via Wave (aucun proxy nginx, lecture directe), CSP img/media limitée à `*.dzcdn.net`, API en `default-src 'none'`. Compteurs repost : `INSERT … ON CONFLICT DO NOTHING` + bump conditionnel, notification non lue unique (lecture, pas de test multi-processus ajouté).

Constats, par gravité (correctif proposé, propriétaire backend) :
- F32 moyen, `src/Repository/RepostRepository.php:158-167` (branche repost de `followingActivityPage`) : ne joint pas l'auteur du post, seul le reposteur est filtré sur `suspended_at`. Le post public d'un auteur suspendu arrive chez les abonnés du reposteur dans `/feed/following`, avec URL de clip et droit d'écoute (`PostMapper` → grantMany), alors que `/posts/{id}` répond 404. Correctif : `JOIN app_user u ON u.id = p.author_id` et `TrackAudience::publicPostPlayableSql('p','t','u')` dans cette branche. Test : `testFollowingFeedHidesRepostOfSuspendedAuthor`.
- F33 faible, `src/Catalog/DeezerCatalogProvider.php:253-266` (`urlOrNull`, `scalar` pour `link`) : les URL du fournisseur passent telles quelles ; `deezerUrl` devient un `<a href>` (`frontend/src/features/catalog/CatalogParts.tsx:23-33`), `javascript:alert(1)` est renvoyé (React 19 et la CSP limitent l'impact). Correctif : `deezerUrl` seulement si `^https://www\.deezer\.com/`, sinon `https://www.deezer.com` ; picture/cover/preview seulement `^https://[a-z0-9-]+\.dzcdn\.net/`, sinon null. Test : `testCatalogRejectsNonDeezerUrlsFromProvider`.
- F34 faible (lecture, non testé en multi-processus), `src/State/Processor/RepostProcessor.php:58-68` + `RepostRepository.php:29-33` : un post supprimé entre `find` et l'INSERT donne une violation de clé étrangère non interceptée → 500 (même motif que F17). Correctif : intercepter `ForeignKeyConstraintViolationException` → 404.
- Info : vue d'ensemble de recherche = 5 requêtes pour 1 jeton du limiteur ; tsquery sans plafond de lexèmes (50 préfixes d'une lettre, coûteux à grande échelle : plafonner à ~8 lexèmes ou 2 car. mini) ; routes `/hashtags*` sans limiteur ; fil abonnements sans borne sur l'ensemble d'activités (scan séquentiel, ok au volume actuel) ; résumé d'artiste mis en cache sous l'id demandé même si le fournisseur renvoie un autre id.

## Décisions prises
Aucune. Numérotation proposée F32-F34 pour `docs/SECURITY.md` (non modifié, au lead).

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
Backend : corriger F32 (prioritaire avant mise en ligne du repost), F33, F34 ; les deux tests en échec repasseront au vert. Info à arbitrer par le lead.

## Commande pour vérifier
`make test c="--no-progress tests/Security/PhaseFourSecurityTest.php" 2>&1 | tail -40` (attendu aujourd'hui : 2 échecs F32, F33).
