# W-80 · backend — Repost (ADR 015)

## Fait
- Migration `Version20261116122458` appliquée en base de test ET en dev (table `repost`, `post.repost_count`, CHECK étendus). SQL ci-dessous.
- `PUT/DELETE /posts/{postId}/repost` idempotents (INSERT ON CONFLICT / DELETE, miroir de post_like) : 422 `cannot_repost_own`, 422 `repost_not_allowed` (audience non publique), 404 `not_found` (non jouable), 403 e-mail non vérifié (PUT), 429 `rate_limited` (`repost_hour_user` 30/h, `repost_day_user` 200/j ; DELETE non limité). Notification `repost` agrégée (exclut l'auto-repost).
- `GET /feed/following` réécrit (activité) : 1 ligne/post à sa dernière activité (auteur suivi+jouable, OU repost par un suivi non suspendu d'un post publiquement jouable non-perso), keyset `(activity_at, post_id)`, curseur v=`f2`. Curseurs phase 3 → 400 `invalid_cursor` ; limite invalide → 400 `malformed_request` (compat W-32).
- `GET /users/{username}/reposts` (repost desc, filtre live, profil suspendu → 404).
- Hydratation sans N+1 : `repostedAmong` + `canRepost` en requêtes groupées ; attribution `repostedBy`/`repostedAt` passée au mapper. §13b : `RepostRepository::purgeByUser` décrémente puis supprime.
- Tests : `tests/Functional/Social/RepostTest.php` (7 tests, 159 assert.) — idempotence/compteurs/champs, codes 422/404/403/429, N+1 (page 2 vs 20 = mêmes requêtes), filtres live feed+reposts (compteurs intacts), curseur phase 3→400, §13b décrément + cascade suppression post.

## Décisions prises
- Curseur feed/following versionné `f2` via `encodePayload` → rejet propre des curseurs phase 3.
- Compteur `repost_count` dénormalisé + CHECK `>= 0` (comme like/comment/view).
- FK `repost.post_id` ON DELETE CASCADE ; §13b décrémente AVANT le DELETE (la cascade seule laisserait le compteur faux).

## Contrat d'API modifié ? non
Conforme à `docs/openapi.yaml` (listUserReposts, repostPost/unrepostPost ordre 404→422own→422restricted, getFollowingFeed, RepostState, PostView +repostCount/repostedByMe/canRepost/repostedBy/repostedAt). Aucune divergence.

## Reste à faire / points ouverts
- ADR 015 §6 : le ranker W-40 (feed for-you) N'intègre PAS encore le repost (poids présents mais inutilisés). NON touché — à câbler dans un lot dédié.
- §13b : l'effaceur de compte (`DELETE /me`) n'appelle pas encore `purgeByUser` (méthode prête + testée unitairement). À brancher quand l'effaceur existera.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh back` · test ciblé : `docker compose exec -T php php bin/phpunit --no-progress tests/Functional/Social/RepostTest.php`

## SQL migration (up) — relu par le lead, pas de DROP d'objet écrit à la main
```sql
CREATE TABLE repost (created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, post_id UUID NOT NULL, PRIMARY KEY (user_id, post_id));
CREATE INDEX idx_repost_post ON repost (post_id);
CREATE INDEX idx_repost_user_created ON repost (user_id, created_at DESC, post_id DESC);
CREATE INDEX IDX_DD3446C5A76ED395 ON repost (user_id);
ALTER TABLE repost ADD CONSTRAINT FK_DD3446C5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE;
ALTER TABLE repost ADD CONSTRAINT FK_DD3446C54B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE;
ALTER TABLE post ADD repost_count INT DEFAULT 0 NOT NULL;
ALTER TABLE post DROP CONSTRAINT chk_post_counters, ADD CONSTRAINT chk_post_counters CHECK (like_count >= 0 AND comment_count >= 0 AND view_count >= 0 AND repost_count >= 0);
ALTER TABLE notification DROP CONSTRAINT chk_notification_target, ADD CONSTRAINT chk_notification_target CHECK (type IN ('post_like', 'comment', 'repost'));
```
`doctrine:schema:update --dump-sql` : aucune diff liée au repost (index DESC comparés par colonnes).

## EXPLAIN /feed/following — 10 000 posts / 5 000 reposts (ANALYZE, rollback)
Append 15k lignes d'activité → Sort (quicksort 1.2 Mo) → WindowAgg ROW_NUMBER → top-N heapsort → Limit 11.
`Execution Time ≈ 19 ms`, `Planning ≈ 2 ms`. Seq scans sur `post` (passage complet moins cher qu'un index à cette taille). Acceptable.
