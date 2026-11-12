# Phase 3 vague 3 — qa-security (`020f492` W-33/W-45, `3bbd119` W-34/W-35)

## Fait
- Revue diff + voisins (security.yaml, RateLimitListener, UserChecker/UserProvider, lookups, TrackAudience, client.ts). 0 critique, 0 élevé, 2 moyens, 4 bas.
- Tests `backend/tests/Security/PhaseThreeWaveThreeSecurityTest.php` (5) : 3 verts (6e signaleur après masquage = 404 octet pour octet comme un uuid inconnu, en-têtes compris ; JWT encore valide d'un modérateur rétrogradé → 403 ; `/api/v1/%61dmin…` → 403/404). **2 rouges voulus = findings ouverts** : `testOpen…` ci-dessous. PHPStan et cs-fixer propres.

## Décisions prises (verdicts des points du lead)
1. `ViewNormalizer` `{}` : pas de régression. Seul `\stdClass` est touché et le seul `\stdClass` de `src/` est `(object) $reasons` (`ReportMapper.php:123`) ; `SessionIssuer` et les autres vues n'en ont pas ; le front ne lit pas `reasons` (écran admin à venir).
2. W-33 : le 404 du 6e signaleur concurrent n'est pas un oracle (même réponse qu'en séquentiel et qu'un uuid inconnu, testé ; le reçu 201 n'a aucun champ lié au masquage). Toutes les routes `/admin` : `access_control` avant tout provider + `security` d'opération, rôles relus en base à chaque requête. Suspension : `UserChecker` coupe le JWT encore valide (403 `account_suspended`, testé par W-33) et les refresh tokens sont supprimés.
3. W-45 : limite `notifications` sur le seul `unread-count` = conforme à openapi.yaml (bas 1). FB-18 et suspendus : filtres corrects et testés (acteurs suspendus, post sur piste devenue invisible, contenus propres masqués/restreints listés). Comparaison à la seconde : moyen 1.
4. Front : légendes, hashtags, noms en nœuds texte React (aucun `dangerouslySetInnerHTML`), liens `encodeURIComponent` ; URL de clip seulement en mémoire et dans `audio.src`, pas de log ; vues et écoute en `keepalive` à `pagehide`/sortie, lots de 50 (bas 3).

## Findings
- **Moyen 1 (backend W-45, contrat)** `NotificationRepository.php:221` : `date_trunc('second', updated_at) <= upTo` marque lu un item arrivé après l'affichage dans la même seconde que le plus récent affiché (contrat : « an event arriving meanwhile stays unread »). Test `testOpenItemArrivedInTheSameSecondAfterDisplayStaysUnread`. Correctif : `NotificationPage.readUpTo` opaque (max `updated_at` en µs de la 1re page) renvoyé tel quel à `read`, ou `updatedAt` en ms pour les notifications : changement de contrat, à trancher par l'architecte avant W-46.
- **Moyen 2 (backend W-33)** `PlaylistProcessor.php:46`, `ArtistProcessor.php:92` : supprimer une playlist ou un artiste signalé laisse ses signalements ouverts ; la cible reste à vie dans la file (`preview: null`) et sa décision répond 404 (`ModerationDecisionProcessor.php:56`). Test `testOpenDeletedPlaylistOrArtistLeavesNoStuckQueueEntry`. Correctif : `DELETE FROM report WHERE target_type = 'playlist'|'artist' AND target_id = :id` dans la transaction de suppression (comme `CommentRepository.php:101`, `PostCleanup`).
- Bas 1 (W-45) `RateLimitListener.php:58` : `read` et la liste sans limite ; conforme au contrat, `read` est idempotent et borné aux lignes de l'appelant. Aligner le texte du ticket, ou ajouter `x-rate-limit` à `read` si voulu.
- Bas 2 (W-33, décision) `ModerationDecisionProcessor.php:63` : un modérateur peut suspendre un admin ou un autre modérateur. Proposé : 422 si la cible a un rôle ≥ celui du décideur (phase 5).
- Bas 3 (W-34) `api/client.ts:222-226` : à `pagehide` avec un JWT expiré, le renvoi après refresh part d'une page déchargée : vues et écoute perdues. Acceptable.
- Bas 4 (à vérifier en W-37) : jetons `/stream` (pare-feu sans utilisateur) d'un compte qui vient d'être suspendu, valables jusqu'à 5 min ?

## Contrat d'API modifié ?
Non. Moyen 1 en demande un (à décider).

## Reste à faire / points ouverts
- Corriger moyen 2 (backend, sans contrat) ; décider moyen 1. Les 2 tests rouges cassent la suite tant que ce n'est pas fait.

## Commande pour vérifier
`make test c="--no-progress --filter PhaseThreeWaveThreeSecurityTest" 2>&1 | tail -40` (attendu aujourd'hui : 5 tests, 2 échecs voulus)
