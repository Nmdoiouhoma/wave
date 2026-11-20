# W-88 backend — supervision admin (FB-34)

## Fait
- `GET /admin/dashboard/supervision` : `src/ApiResource/AdminSupervisionView.php` + `src/State/Provider/AdminSupervisionProvider.php` (3 requêtes, cache 30 s pool `cache.admin_dashboard`, horloge PSR injectée). Seuils dans `src/Admin/SupervisionThresholds.php`, niveaux `src/Enum/SupervisionLevel.php` (pire gagne, sévérité → niveau).
- Tests : `tests/Unit/Admin/SupervisionThresholdsTest.php` (chaque palier 20/50/80 %, plancher 20 tentatives, paliers 1/10/50, sévérités, cumul, jours DST de 23 h et 25 h), `tests/Functional/Admin/AdminSupervisionTest.php` (401/403, forme nulle modérateur, modérateur après admin sans fuite du cache, signaux/niveaux/histogramme, aucune donnée personnelle), route ajoutée à `tests/Security/BackOfficeSecurityTest.php`.
- EXPLAIN sur 100 k événements (base de test, transaction annulée) : fenêtre 1 h → Index Scan `idx_activity_event_created` ; histogramme 24 h → BitmapOr sur `idx_activity_event_type_created` ; alertes → Index Only Scan `idx_security_alert_status`. Aucune migration.

## Décisions prises
- Modérateur : 200 forme nulle (contrat), construite sans cache ni base ; seul l'admin passe par le cache.
- Paliers inclusifs sur la borne basse : connexions ≥ 80 % = critique ; abus/uploads 1→attention, 10→alerte, 50→critique.
- Seaux horaires = heures absolues (tronquées en UTC ; décalages de Paris en heures entières) : 24 heures consécutives même au changement d'heure, le 02 h d'octobre apparaît deux fois.
- Niveau d'une heure = pire de : sévérités des alertes ouvertes vues pour la première fois cette heure-là, et seuils connexions/abus/uploads appliqués aux comptes de l'heure. `count` = alertes ouvertes + `login_failed` + `rate_limited` + `upload.rejected` (comme le contrat : alertes acquittées exclues).
- `detail` en français ; sous le plancher : « trop peu de tentatives (N sur 1 h) ».

## Contrat d'API modifié ? (oui/non + détail)
Oui, additif (les 3 écarts du front W-89) : `SupervisionSignal` gagne `measured` (bool, faux seulement pour `logins` sous 20 tentatives), `severities` ({high, medium, low} pour `suspicious`, même instantané que `value` ; null sinon), `thresholds` (bornes basses attention/alerte/critique dans l'unité de `value` ; null pour `suspicious`). Description du `level` horaire précisée. `frontend/src/api/schema.d.ts` régénéré (`npm run gen:api`) ; le front peut lâcher les compteurs de `/admin/dashboard` et les 20/50/80 en dur (lot front à prévoir, non fait ici).

## Reste à faire / points ouverts
- Front : utiliser `severities`, `measured`, `thresholds` (W-89 suite).
- DATA_MODEL §16 note : parle de `GROUP BY (created_at AT TIME ZONE 'Europe/Paris')` ; l'implémentation tronque en UTC (équivalent pour Paris) — à reformuler par l'architecte si souhaité.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh back` puis `front` → VERDICT OK.
