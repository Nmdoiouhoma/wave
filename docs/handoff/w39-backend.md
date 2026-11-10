# W-39 — backend : fil « Pour toi » v1 (FB-12, ADR 011)

## Fait
- `App\Feed\` : `FeedRanker` (interface), `SimpleFeedRanker`, `ForYouCursor`, `ForYouWeights`, `RankedPage` ; `GET /feed/for-you` (`ForYouFeedProvider`, `PostPage`).
- Une requête SQL par phase (u : non vus par score ; s : vus avant now₀ par `last_seen_at`). Les candidats passent par `TrackAudience::sql('t')` (test de mutation : remplacer le fragment par `true` fait échouer le test d'exclusions).
- Plafond de 2 posts par auteur et exclusions communs aux deux phases, compteur conservé au passage u→s dans une même page.
- Fichiers partagés, en ajout seulement : `CursorCodec::encodePayload/decodePayload`, `PostRepository::findManyInOrder`, alias `FeedRanker` dans `config/services.yaml`, opération `getForYouFeed` dans `ApiResource/PostView.php`.
- Tests : `tests/Functional/Feed/ForYouFeedTest.php` (12) + `ForYouPerformanceTest.php` (5 000 posts, 200 utilisateurs, 20 % de morceaux restreints).
- Mesures : page u 16-21 ms, page s ~1 ms, requête HTTP complète 22-25 ms (budget 100 ms). Suite complète : 443 tests OK.

## Décisions prises
- Gigue : `abs(CAST(hashtext(…) AS bigint))` pour éviter le dépassement de `abs(int4 min)`.
- now₀ pris à l'horloge PHP (`ClockInterface`) et porté par le curseur ; seed = 8 hexadécimaux aléatoires.
- Curseur : l'id accepte tout uuid canonique en minuscules (pas seulement les versions RFC), car il n'est comparé qu'en SQL.
- CTE `c AS MATERIALIZED` : le score est calculé une seule fois (page suivante 27 → 17 ms).
- `limit` validé avant le curseur (0, 21 ou non numérique → 400 malformed_request, même message que PageRequest).
- Test de perf gardé dans la suite normale (~1 s), marqué `#[Group('performance')]` ; exclusion possible par `--exclude-group performance`.
- Tests de vues entre deux pages : écriture avec `clock_timestamp()`, car `now()` est figé dans la transaction DAMA.

## Contrat d'API modifié ?
Non. `/feed/for-you` est implémenté tel que dans openapi.yaml. Pas de migration : aucun index ne manque (phase s en Index Only Scan sur `idx_post_view_user_seen`, phase u en parcours complet comme le prévoit l'ADR).

## Reste à faire / points ouverts
- verif.sh back (dernier passage) : cs-fixer OK, phpunit OK (443 tests). PHPStan complet tué par OOM (conteneur php plafonné à 512 Mo, agents W-31/W-32 en parallèle) ; PHPStan ciblé sur mes fichiers : aucune erreur. À relancer par le lead une fois seul.
- W-40 remplacera `SimpleFeedRanker` par `SqlFeedRanker` derrière la même interface.

## Commande pour vérifier
`make test c="--no-progress tests/Functional/Feed"` ; l'EXPLAIN complet est réécrit dans `backend/var/w39-explain.txt`.

---
## Annexe : EXPLAIN ANALYZE (5 000 posts, spectateur avec 1 000 vues ; lignes coupées à 170 caractères)
```
── phase u, page 1
Limit (actual time=16.354..16.363 rows=31 loops=1)
  CTE c
    ->  Hash Left Join (actual time=1.169..15.299 rows=3105 loops=1)
          Hash Cond: ((t.genre_id)::text = (gw.genre_id)::text)
          ->  Hash Join (actual time=0.432..2.564 rows=3105 loops=1)
                Hash Cond: (p.author_id = a.id)
                ->  Hash Join (actual time=0.391..2.193 rows=3105 loops=1)
                      Hash Cond: (p.track_id = t.id)
                      Join Filter: ((((t.visibility)::text = 'public'::text) AND t.allow_clips) OR ((p.author_id = t.owner_id) AND (((t.visibility)::text = 'public'::text
                      Rows Removed by Join Filter: 895
                      ->  Seq Scan on post p (actual time=0.167..1.207 rows=4000 loops=1)
                            Filter: ((author_id <> '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid) AND (created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time z
                            Rows Removed by Filter: 1020
                            SubPlan 4
                              ->  Seq Scan on post_view (actual time=0.002..0.069 rows=1000 loops=1)
                                    Filter: (user_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                      ->  Hash (actual time=0.216..0.216 rows=1000 loops=1)
                            Buckets: 1024  Batches: 1  Memory Usage: 85kB
                            ->  Seq Scan on track t (actual time=0.001..0.117 rows=1000 loops=1)
                                  Filter: ((moderation)::text = 'visible'::text)
                      SubPlan 2
                        ->  Seq Scan on follow follow_1 (actual time=0.001..0.004 rows=50 loops=1)
                              Filter: (follower_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                      SubPlan 3
                        ->  Seq Scan on close_friend (actual time=0.001..0.002 rows=10 loops=1)
                              Filter: (friend_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                ->  Hash (actual time=0.037..0.037 rows=200 loops=1)
                      Buckets: 1024  Batches: 1  Memory Usage: 18kB
                      ->  Seq Scan on app_user a (actual time=0.002..0.018 rows=200 loops=1)
                            Filter: (suspended_at IS NULL)
          ->  Hash (actual time=0.713..0.716 rows=6 loops=1)
                Buckets: 1024  Batches: 1  Memory Usage: 9kB
                ->  Subquery Scan on gw (actual time=0.706..0.713 rows=6 loops=1)
                      ->  WindowAgg (actual time=0.706..0.712 rows=6 loops=1)
                            ->  HashAggregate (actual time=0.700..0.703 rows=6 loops=1)
                                  Group Key: lt.genre_id
                                  Batches: 1  Memory Usage: 24kB
                                  ->  Hash Join (actual time=0.575..0.684 rows=160 loops=1)
                                        Hash Cond: (lt.id = tl.track_id)
                                        ->  Seq Scan on track lt (actual time=0.001..0.045 rows=1000 loops=1)
                                        ->  Hash (actual time=0.571..0.573 rows=160 loops=1)
                                              Buckets: 1024  Batches: 1  Memory Usage: 16kB
                                              ->  Append (actual time=0.003..0.559 rows=160 loops=1)
                                                    ->  Seq Scan on track_like tl (actual time=0.002..0.010 rows=60 loops=1)
                                                          Filter: ((created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time zone) AND (user_id = '01a1c5c9-afa0
                                                    ->  Hash Join (actual time=0.027..0.538 rows=100 loops=1)
                                                          Hash Cond: (lp.id = pl.post_id)
                                                          ->  Seq Scan on post lp (actual time=0.001..0.253 rows=5020 loops=1)
                                                          ->  Hash (actual time=0.023..0.023 rows=100 loops=1)
                                                                Buckets: 1024  Batches: 1  Memory Usage: 13kB
                                                                ->  Seq Scan on post_like pl (actual time=0.002..0.015 rows=100 loops=1)
                                                                      Filter: ((created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time zone) AND (user_id = '0
          SubPlan 1
            ->  Seq Scan on follow (actual time=0.002..0.004 rows=50 loops=1)
                  Filter: (follower_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
  ->  Sort (actual time=16.354..16.355 rows=31 loops=1)
        Sort Key: c.score DESC, c.id DESC
        Sort Method: top-N heapsort  Memory: 30kB
        ->  CTE Scan on c (actual time=1.170..15.982 rows=3105 loops=1)
Planning Time: 0.618 ms
Execution Time: 16.477 ms
── phase u, next page
Limit (actual time=16.530..16.538 rows=31 loops=1)
  CTE c
    ->  Hash Left Join (actual time=1.191..15.400 rows=3105 loops=1)
          Hash Cond: ((t.genre_id)::text = (gw.genre_id)::text)
          ->  Hash Join (actual time=0.451..2.568 rows=3105 loops=1)
                Hash Cond: (p.author_id = a.id)
                ->  Hash Join (actual time=0.411..2.198 rows=3105 loops=1)
                      Hash Cond: (p.track_id = t.id)
                      Join Filter: ((((t.visibility)::text = 'public'::text) AND t.allow_clips) OR ((p.author_id = t.owner_id) AND (((t.visibility)::text = 'public'::text
                      Rows Removed by Join Filter: 895
                      ->  Seq Scan on post p (actual time=0.163..1.167 rows=4000 loops=1)
                            Filter: ((author_id <> '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid) AND (created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time z
                            Rows Removed by Filter: 1020
                            SubPlan 4
                              ->  Seq Scan on post_view (actual time=0.002..0.071 rows=1000 loops=1)
                                    Filter: (user_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                      ->  Hash (actual time=0.239..0.239 rows=1000 loops=1)
                            Buckets: 1024  Batches: 1  Memory Usage: 85kB
                            ->  Seq Scan on track t (actual time=0.001..0.136 rows=1000 loops=1)
                                  Filter: ((moderation)::text = 'visible'::text)
                      SubPlan 2
                        ->  Seq Scan on follow follow_1 (actual time=0.001..0.004 rows=50 loops=1)
                              Filter: (follower_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                      SubPlan 3
                        ->  Seq Scan on close_friend (actual time=0.001..0.002 rows=10 loops=1)
                              Filter: (friend_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                ->  Hash (actual time=0.037..0.037 rows=200 loops=1)
                      Buckets: 1024  Batches: 1  Memory Usage: 18kB
                      ->  Seq Scan on app_user a (actual time=0.002..0.019 rows=200 loops=1)
                            Filter: (suspended_at IS NULL)
          ->  Hash (actual time=0.717..0.720 rows=6 loops=1)
                Buckets: 1024  Batches: 1  Memory Usage: 9kB
                ->  Subquery Scan on gw (actual time=0.711..0.717 rows=6 loops=1)
                      ->  WindowAgg (actual time=0.710..0.716 rows=6 loops=1)
                            ->  HashAggregate (actual time=0.705..0.708 rows=6 loops=1)
                                  Group Key: lt.genre_id
                                  Batches: 1  Memory Usage: 24kB
                                  ->  Hash Join (actual time=0.569..0.689 rows=160 loops=1)
                                        Hash Cond: (lt.id = tl.track_id)
                                        ->  Seq Scan on track lt (actual time=0.001..0.050 rows=1000 loops=1)
                                        ->  Hash (actual time=0.565..0.567 rows=160 loops=1)
                                              Buckets: 1024  Batches: 1  Memory Usage: 16kB
                                              ->  Append (actual time=0.002..0.554 rows=160 loops=1)
                                                    ->  Seq Scan on track_like tl (actual time=0.002..0.012 rows=60 loops=1)
                                                          Filter: ((created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time zone) AND (user_id = '01a1c5c9-afa0
                                                    ->  Hash Join (actual time=0.035..0.532 rows=100 loops=1)
                                                          Hash Cond: (lp.id = pl.post_id)
                                                          ->  Seq Scan on post lp (actual time=0.001..0.248 rows=5020 loops=1)
                                                          ->  Hash (actual time=0.029..0.030 rows=100 loops=1)
                                                                Buckets: 1024  Batches: 1  Memory Usage: 13kB
                                                                ->  Seq Scan on post_like pl (actual time=0.002..0.019 rows=100 loops=1)
                                                                      Filter: ((created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time zone) AND (user_id = '0
          SubPlan 1
            ->  Seq Scan on follow (actual time=0.002..0.004 rows=50 loops=1)
                  Filter: (follower_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
  ->  Sort (actual time=16.529..16.530 rows=31 loops=1)
        Sort Key: c.score DESC, c.id DESC
        Sort Method: top-N heapsort  Memory: 31kB
        ->  CTE Scan on c (actual time=1.193..16.164 rows=3055 loops=1)
              Filter: (ROW(score, id) < ROW(0.746813906, 'fa9be0f9-d052-86f6-d46e-c94dd7ea45c5'::uuid))
              Rows Removed by Filter: 50
Planning Time: 0.640 ms
Execution Time: 16.657 ms
── phase s, next page
Limit (actual time=0.031..0.143 rows=31 loops=1)
  ->  Nested Loop (actual time=0.031..0.142 rows=31 loops=1)
        ->  Nested Loop (actual time=0.022..0.096 rows=31 loops=1)
              ->  Nested Loop (actual time=0.017..0.062 rows=31 loops=1)
                    ->  Index Only Scan using idx_post_view_user_seen on post_view pv (actual time=0.010..0.014 rows=31 loops=1)
                          Index Cond: ((user_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid) AND (last_seen_at < '2026-11-10 14:08:11.946018+00'::timestamp with time z
                          Heap Fetches: 31
                    ->  Index Scan using post_pkey on post p (actual time=0.001..0.001 rows=1 loops=31)
                          Index Cond: (id = pv.post_id)
                          Filter: ((author_id <> '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid) AND (created_at <= '2026-11-10 14:08:11.946018+00'::timestamp with time zon
              ->  Index Scan using track_pkey on track t (actual time=0.001..0.001 rows=1 loops=31)
                    Index Cond: (id = p.track_id)
                    Filter: (((moderation)::text = 'visible'::text) AND ((((visibility)::text = 'public'::text) AND allow_clips) OR ((p.author_id = owner_id) AND (((visib
                    SubPlan 1
                      ->  Seq Scan on follow (never executed)
                            Filter: (follower_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
                    SubPlan 2
                      ->  Seq Scan on close_friend (never executed)
                            Filter: (friend_id = '01a1c5c9-afa0-7822-aec0-eadcff80bd60'::uuid)
        ->  Memoize (actual time=0.001..0.001 rows=1 loops=31)
              Cache Key: p.author_id
              Cache Mode: logical
              Hits: 0  Misses: 31  Evictions: 0  Overflows: 0  Memory Usage: 4kB
              ->  Index Scan using app_user_pkey on app_user a (actual time=0.001..0.001 rows=1 loops=31)
                    Index Cond: (id = p.author_id)
                    Filter: (suspended_at IS NULL)
Planning Time: 0.419 ms
Execution Time: 0.181 ms
```
