# W-40 backend — Recommendations v1

## Fait
- `SqlFeedRanker` (ex-`SimpleFeedRanker`, supprimé), alias DI `FeedRanker` → `SqlFeedRanker`, score ADR 005 sur `user_affinity` + `trending_score`, fenêtre candidats 30 j.
- `Recommendation/AffinityRecomputer` + `TrendingRecomputer` (SQL pur) ; handlers `RecomputeAffinity` (stub remplacé), `RecomputeAffinityAll` (utilisateurs actifs 30 j), `RecomputeTrending` ; planifiés dans `WaveSchedule` (`*/15 * * * *`, `0 4 * * *`). CONVENTIONS §8 déjà correct, inchangé.
- Poids/fenêtres/demi-vies : une seule classe `Feed/ForYouWeights`.
- Entité `UserAffinity` + migration `Version20261113000500` (écrite à la main, appliquée en dev, `schema:update --dump-sql` ne propose rien sur cette table) :
  `CREATE TABLE user_affinity (kind VARCHAR(16) NOT NULL, "key" VARCHAR(64) NOT NULL, score DOUBLE PRECISION NOT NULL, updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id, kind, "key"))` ;
  `CREATE INDEX idx_user_affinity_score ON user_affinity (user_id, kind, score DESC)` ; `ALTER TABLE user_affinity ADD CONSTRAINT FK_USER_AFFINITY_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE`. Aucun index manuel supprimé.
- Cursor `r` = `sql1` ; `simple1` → 400 `invalid_cursor`.

## Décisions prises
- Écart à l'ADR 005 : terme `0.30·T·R` devenu `0.30·R·(0.25 + 0.75·T)` (`TREND_FLOOR`), sinon la fraîcheur disparaît au démarrage à froid (T = 0) et le test W-39 « cold start ordonné par fraîcheur » échoue. ADR 005 à amender.
- Phase s (déjà vus) : on garde « le moins récemment vu d'abord » (ADR 011, test W-39) au lieu de `trending_score` de l'ADR 005.
- T normalisé par le max de `post.trending_score` (lu via `idx_post_ready_trending`) ; recalculé toutes les 15 min, donc un score peut bouger entre deux pages (déjà toléré par ADR 011).
- Tests W-39 : comportement inchangé ; seuls les helpers (`setCounts` écrit aussi `trending_score`, `likeTrack` appelle `AffinityRecomputer`) et le cursor `simple1`→`sql1` ont changé (le ranker lit désormais des signaux précalculés). Ajout de `testRecomputeJobsFillAffinityAndTrending`.

- Repost (ADR 015 §6, consigne du lead) : `REPOST_FOLLOW` 0.75, `REPOST_TRENDING` 4, `REPOST_AFFINITY` 3 ajoutés dans `ForYouWeights`, inutilisés (« wired in W-80 »).

## Contrat d'API modifié ? non
`/feed/for-you` identique ; seul changement : les anciens cursors `simple1` → 400 (prévu W-34, le client redémarre). ADR 011/005 et DATA_MODEL §12 : mentionner `sql1`, le plancher et la phase s.

## Reste à faire / points ouverts
- p95 sur seed démo non mesuré. EXPLAIN (dev, 17 posts, 0 ligne) : plan sans surprise ; index utilisés : `idx_post_ready_trending` (max), `user_affinity_pkey` (G, A), `post_view_pkey`/`idx_post_view_user_seen`, `follow_pkey`, `idx_post_like_post` (F). Reste un scan des posts des 30 derniers jours (ADR 005 : ok < ~100 k posts) : l'index partiel (created_at DESC, id DESC) de `post` (DATA_MODEL §9) à vérifier sur seed démo.
- Aucun déclenchement initial : `user_affinity` vide tant que `RecomputeAffinityAll` (04:00) ou un like/écoute ne tourne pas (le seed dev n'a rien à recalculer avant ces déclencheurs).

## Commande pour vérifier
`make test c="--no-progress --filter ForYou" 2>&1 | tail -20` ; `bash .claude/skills/wave-verif/verif.sh back`
