## Fait
- Like de post (`PUT/DELETE /posts/{id}/like`, LikeProcessor), commentaires (`GET/POST /posts/{id}/comments`, `DELETE /comments/{id}` : CommentView, CommentRepository, CommentMapper, 3 processeurs/fournisseurs), vues (`POST /post-views` : PostViewsProcessor, PostViewRepository, upsert DBAL en une requête), écoute `source=feed` (ListenProcessor, ListenRecorder écrit `listen.post_id`), fil Abonnements (`GET /feed/following` : FollowingFeedProvider, `PostRepository::findFollowingPage`).
- « Jouable » partout via TrackAudience : `isPostPlayable` (PostLookup::playable) et nouveaux fragments `postPlayableSql()` / `postPlayableDql()` (vues, fil Abonnements).
- Limites : `comment_minute_user` 10/min + `comment_day_user` 200/jour, `post_view_user` 60/min, like de post = `social_write_user`.
- Tests : tests/Functional/Post/PostInteractionTest.php (8), tests/Security/PostInteractionAudienceTest.php (4, dont le test du ticket : abonné compté, étranger 404/422, abonné 422 après désabonnement). Aucune migration.

## Décisions prises
- Unlike : like existant toujours retirable (F14) ; sans like, post « visible » requis sinon 404 identique à l'inconnu. Like et commentaire exigent « jouable », y compris pour un modérateur.
- Supprimer un commentaire supprime aussi les signalements qui le visent (`report` target_type=comment).
- `comment_count` ne compte que les commentaires visibles (−1 seulement si visible à la suppression).
- `/post-views` : doublons d'un lot fusionnés au max ; ids triés et `FOR UPDATE` ordonné (pas d'interblocage) ; id propre, inconnu ou non jouable ignoré (204).
- Écoute feed : toute anomalie (postId absent, inconnu, autre morceau, non jouable) = même 422 ; `postId` avec `source=player` = 422.

## Contrat d'API modifié ? (oui/non + détail)
- Non. Opérations implémentées telles que dans docs/openapi.yaml.

## Reste à faire / points ouverts
- Fichiers partagés touchés par ajout seulement : Security/TrackAudience.php (2 méthodes), Repository/PostRepository.php (`findFollowingPage`), ApiResource/PostView.php (opération `getFollowingFeed`), State/Provider/PostLookup.php (`playable`), ApiResource/LikeStateView.php, RateLimit/RateLimitListener.php, config/packages/rate_limiter.yaml, Listen/ListenRecorder.php (paramètre `postId` optionnel).
- `PostRepository::findPageByAuthor` garde une condition « jouable » écrite à la main : à remplacer par `TrackAudience::postPlayableDql()` (non fait, hors ticket).
- W-39 (fil Pour toi) devrait réutiliser `postPlayableSql()` plutôt qu'un prédicat à lui.

## Commande pour vérifier
make test c="--no-progress tests/Functional/Post/PostInteractionTest.php" ; make test c="--no-progress tests/Security/PostInteractionAudienceTest.php" ; bash .claude/skills/wave-verif/verif.sh back
