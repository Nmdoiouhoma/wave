## Fait
- `backend/tests/Security/PhaseThreeReviewSecurityTest.php` (8 tests, 7 verts, 1 `testOpen…` rouge = F19) ; PHPStan + cs-fixer OK.
- `docs/SECURITY.md` : menaces T22-T25, findings phase 3 F19-F24.
- Vérifié sans finding (testé) : oracles FB-18 (stranger, ex-follower, follower hors amis proches, ami proche retiré) sur signalements track/post/comment, création de post, 7 routes post, suppression de commentaire : 404 identique octet pour octet (en-têtes compris) à un id inconnu, rien écrit, vues ignorées ; masqués / piste masquée / auteur suspendu absents de for-you, following, profil, commentaires, 404 par id ; brigading (409 doublon, 5 comptes masquent post + commentaire, URL de clip antérieure → 404, `dismiss` restaure `comment_count`, mêmes comptes → 409) ; jetons de piste et de clip d'un spectateur suspendu entre-temps → 404 (bas 4 v3 corrigé) ; multipart hostile (champ inconnu, tableau, NUL, UTF-8 invalide en valeur, `1e3`, négatif → 422) ; hashtags (zero-width, bidi, BOM, pleine chasse, plafond 50) ; boucles like/unlike et follow/unfollow → au plus 1 non lu ; `read` borné à soi, champ en trop → 422.
- Relu sans finding : limites de débit = `x-rate-limit` du contrat, `/admin` en ROLE_MODERATOR, CSP API + nosniff, aucun `dangerouslySetInnerHTML`, `upTo` renvoyé tel quel, acteurs sans e-mail, signalement masqué sur son propre contenu (5 pages).

## Findings (0 critique, 0 élevé, 0 moyen, 6 bas)
- F19 bas — `backend/src/State/Processor/CreatePostProcessor.php:125` : nom de champ multipart en UTF-8 invalide renvoyé dans `field` du 422, `JsonResponse` (`ProblemResponseFactory.php:33`) lève dans l'écouteur → 500. Correctif : ne pas renvoyer le nom (`field: null`) ou le nettoyer (`mb_scrub`) ; idéalement `JSON_INVALID_UTF8_SUBSTITUTE` dans la fabrique. Test : `testOpenInvalidUtf8MultipartFieldNameIsA422NotA500`.
- F20 bas (spec) — `backend/src/Post/HashtagExtractor.php:25` : `[\p{L}\p{N}_]` sans `\p{M}`, `#हिन्दी` → `ह`, arabe vocalisé tronqué. Correctif : ajouter `\p{M}` (DATA_MODEL §9 + NAME_PATTERN), décision architecte.
- F21 bas (décision) — `ModerationApplier` / `ReportRepository` : `dismiss` non définitif, 5 nouveaux comptes re-masquent. Correctif : marqueur « revu » qui relève le seuil ou exige un modérateur.
- F22 bas — `CreateCommentProcessor.php:52` : le trim ignore `\p{Cf}` ; un corps de seuls U+200B passe. Correctif : inclure `\x{200B}-\x{200D}\x{2060}\x{FEFF}` dans le trim.
- F23 bas — `ModerationDecisionProcessor.php:87` : `refresh()` après la transaction ; cible supprimée en parallèle → `EntityNotFoundException` → 500. Correctif : relire par id, 404 si absente.
- F24 bas front — `frontend/src/features/comments/CommentsPanel.tsx:117` : `canReport = !mine` vrai tant que `me` n'est pas chargé ; bouton visible sur son propre commentaire, serveur 422. Correctif : `canReport = meId !== null && !mine`.
- Rappel F18 : posts sur la piste d'un propriétaire suspendu toujours jouables (`TrackAudience` ne regarde pas la suspension du propriétaire).

## Décisions prises
- Aucune décision de contrat ; F20 et F21 demandent un arbitrage architecte.

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
- Corriger F19 (backend), F22-F23 (backend), F24 (frontend) ; trancher F20, F21.

## Commande pour vérifier
`make test c="--no-progress --filter PhaseThreeReviewSecurityTest" 2>&1 | tail -40` (1 échec attendu : F19)
