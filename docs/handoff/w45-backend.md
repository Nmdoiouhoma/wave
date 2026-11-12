## Fait
- `NotificationRepository` (SQL seul, DATA_MODEL §8b) : upsert sur `uniq_notification_unread` (`ON CONFLICT … WHERE read_at IS NULL`, inférence NULLS NOT DISTINCT OK), acteur `ON CONFLICT DO NOTHING`, retrait d'acteur + suppression de l'item vide, `deleteOrphans()` (§13b étape 4, prêt pour `DELETE /me` en phase 5).
- Écritures branchées dans les transactions existantes, seulement si une ligne a été insérée/supprimée : `FollowRepository::follow/unfollow`, `LikeRepository::like/unlike/removeOwn` (3 cibles), `CreateCommentProcessor`. Pas de notification à soi-même (filtre `owner <> actor` dans l'INSERT … SELECT).
- Routes : `GET /me/notifications` (keyset `(updated_at, id)`), `GET /me/notifications/unread-count` (plafond 100, bucket `notifications_user` 120/min), `POST /me/notifications/read` (204, 422 sur champ inconnu ou date invalide).
- Filtres de lecture communs liste/compteur : acteurs suspendus exclus, item sans acteur omis, post visible par son auteur (jouable, ou piste visible par l'auteur : FB-18) ; piste/playlist toujours visibles pour leur propriétaire.
- Tests : `tests/Functional/Me/NotificationTest.php` (5), + `ConcurrencySecurityTest::testConcurrentLikesAndFollowsMergeIntoOneUnreadNotification` (10 likes + 10 follows en vrais processus → 1 ligne non lue chacun, 10 acteurs).

## Décisions prises
- Ordre des verrous : compteur de la cible (ligne track/playlist/post/app_user) puis notification, partout ; les likes concurrents sont donc sérialisés par la cible avant l'upsert.
- `read` compare `date_trunc('second', updated_at) <= upTo` : le `updatedAt` du fil est à la seconde (CONVENTIONS §4), donc le client qui renvoie la valeur affichée marque bien l'item affiché (sinon les fractions de seconde le laissaient non lu).
- Bucket `notifications` appliqué au seul `unread-count` (seule route annotée `x-rate-limit` dans openapi.yaml) ; le ticket le cite après `read`, à trancher si on veut l'étendre.
- Libellé de post : légende tronquée à 80 caractères, sinon titre de la piste ; image : poster (vidéo) / visuel (image) / pochette de la piste.

## Contrat d'API modifié ? (oui/non + détail)
- Non. Aucune migration (tables W-30 suffisantes).

## Reste à faire / points ouverts
- `RateLimitListener.php` et `rate_limiter.yaml` : une ligne ajoutée chacun, à fusionner avec W-33 s'il les touche aussi.
- Cache de test périmé pour les workers de concurrence après changement de constructeur : `bin/console cache:clear --env=test` (fait une fois sous flock).
- Phase 5 : purge des lues > 90 j ; appel de `deleteOrphans()` dans `DELETE /me`.

## Commande pour vérifier
- `make test c="--no-progress --filter '(NotificationTest|ConcurrencySecurityTest|PostInteractionTest|LikeTest|FollowTest)'" 2>&1 | tail -40`
