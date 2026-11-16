# W-82 backend — Admin dashboard series (FB-31)

## Fait
- `GET /admin/dashboard/series?range=7d|30d` (`AdminDashboardSeriesView` + `AdminDashboardSeriesProvider`), `security: is_granted('ROLE_MODERATOR')`.
- Une seule requête groupée sur `activity_event` (`(category, type) IN (...)` codé en dur + `created_at >= :lower`), `GROUP BY type, (timezone('Europe/Paris', created_at))::date`. Alertes = requête `security_alert` par `first_seen_at` (admin seulement).
- Jours = calendrier Europe/Paris, DST-aware (bucket via `timezone()`), plus ancien d'abord, dernier = aujourd'hui Paris ; trous remplis à 0 ; `days` + chaque série = 7 ou 30 entrées.
- `logins` = `count(DISTINCT actor_id)` ; `moderation.hidden` = `report.auto_hidden` + `admin.content_hidden`.
- Split de rôle : anonyme 401 / user 403 (firewall `^/api/v1/admin` + security op) ; modérateur → `moderation` seul, `activity`/`content`/`alerts` null.
- `range` invalide → 422 `validation_failed`. Cache 5 min, clé `series:{7|30}d:{admin|mod}` (pool `cache.admin_dashboard` réutilisé, `expiresAfter(300)`).

## Décisions prises
- Pas de nouveau pool de cache : réutilisation de `cache.admin_dashboard` avec clé + TTL dédiés (aucune config modifiée).
- Provider sans injection d'horloge (calque le `AdminDashboardProvider` frère qui fait `new \DateTimeImmutable()`).
- Cache clé par (range, niveau de rôle) : l'entrée modérateur ne contient que la modération, zéro fuite des séries admin.

## Contrat d'API modifié ? (oui/non + détail)
Non. Conforme à `openapi.yaml` (`AdminDashboardSeries`/`DailySeries`) et DATA_MODEL §18. Aucune migration (lecture seule, index et pool existants).

## EXPLAIN 100 k événements (index `(category, type, created_at)` = `idx_activity_event_type_created`)
Seed 100 k lignes en transaction ROLLBACK (~72 790 dans la fenêtre 30 j). Plan : `GroupAggregate` ← `Sort` ← `Bitmap Heap Scan` ← `BitmapOr` de 9 `Bitmap Index Scan on idx_activity_event_type_created`, chacun avec `Index Cond: category = … AND type = … AND created_at >= …`. **Aucun Seq Scan.** Execution Time ≈ 91 ms (couvert par le cache 5 min).

## Reste à faire / points ouverts
- Front W-83 consomme ce contrat (toggle 7/30 j, graphe modération seul pour modérateur).
- Le `Sort` fait un `external merge Disk ~2.9 MB` (dû au `count(DISTINCT actor_id)`) ; négligeable vu le cache, à surveiller si le volume explose.

## Commande pour vérifier
`make test c="--filter AdminDashboardSeriesTest --no-progress"` · `bash .claude/skills/wave-verif/verif.sh back` → VERDICT OK (phpstan, cs-fixer, 545 tests, media, audit).
