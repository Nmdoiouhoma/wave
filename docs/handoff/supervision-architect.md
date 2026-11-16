# Brief — Supervision du dashboard admin (FB-34), contrat

Maquette validée par l'utilisateur le 16/11. Objectif : en tête du dashboard admin, un panneau de supervision « à la Zabbix » qui donne l'état de l'application en dégradé de couleurs, à partir des données déjà collectées (`security_alert` §16, `activity_event` §15). Aucune nouvelle entité, aucun nouveau collecteur.

## Paramètres confirmés par l'utilisateur (16/11)
- **Rafraîchissement** : polling front toutes les 30 s, endpoint en cache court (comme `/admin/dashboard`). Pas de Mercure pour ce lot.
- **Seuils** : ceux listés ci-dessous, tels quels (variante « plus sensible » écartée).

## Niveaux d'état (4, rollup « pire sévérité »)
`ok` (vert) · `attention` (jaune) · `alerte` (orange) · `critique` (rouge). L'état global = le pire des signaux. Toujours icône + libellé côté front (couleur jamais seule).

## Signaux et seuils (constantes ajustables côté backend)
- **Activité suspecte** : alertes `security_alert` ouvertes. Mapping sévérité→niveau : `high`→critique (token_reuse, privilege_granted), `medium`→alerte, `low`→attention ; niveau du signal = pire sévérité ouverte, `ok` si aucune.
- **Connexions (fenêtre 1 h)** : taux d'échec = `auth.login_failed` / (`auth.login_succeeded` + `auth.login_failed`). ok < 20 %, attention 20–50 %, alerte 50–80 %, critique > 80 %. **Taux ignoré si < 20 tentatives sur l'heure** (anti-bruit) → ok.
- **Abus / limites (1 h)** : nb d'`abuse.rate_limited`. Seuils proposés ok 0, attention < 10, alerte < 50, critique ≥ 50 (à confirmer).
- **Uploads (1 h)** : nb d'`upload.rejected`. Mêmes paliers indicatifs.

## Histogramme 24 h (remplace l'idée de ruban)
Barres, une par heure glissante sur 24 h (buckets heure Europe/Paris, DST-aware, comme la série §series). Par heure : `count` = nombre d'événements notables (alertes `first_seen_at` + `auth.login_failed` + `abuse.rate_limited`, définir la liste exacte) et `level` = pire sévérité de l'heure. Le front dessine hauteur=count, couleur=level.

## Contrat attendu
- Nouvel endpoint **`GET /admin/dashboard/supervision`** (ROLE_MODERATOR ; split de rôle comme §dashboard : le modérateur ne voit que ce qui relève de la modération — à trancher : signaux login/abus = admin only ? proposer `global`, `signals[]`, `hourly[]` avec champs nullés pour le modérateur). Cache court (30–60 s) ; le front rafraîchit en polling toutes les ~30 s (choix utilisateur).
- Schéma(s) openapi : `SupervisionState` { global: level, signals: [{key, level, value, detail}], hourly: [{hourStart, count, level}] (24), updatedAt }. Résoudre chaque `$ref`.
- DATA_MODEL : une note sous §15/§16 (pas de nouvelle table) ; les seuils dans une classe `SupervisionThresholds` (constantes), testable.
- Tickets : **W-88 backend** (endpoint + calcul + cache + tests rôle/seuils/DST) ∥ ensuite **W-89 frontend** (panneau + histogramme, Sonnet, tokens clair/sombre FB-27). ADR seulement si un arbitrage le mérite (sinon note dans le ticket).

## Pièges
- Réutiliser l'index `(category, type, created_at)` d'`activity_event` et `security_alert (status, severity, last_seen_at)`. EXPLAIN dans le handoff.
- Pas de données par utilisateur dans ce panneau (agrégats seulement) ; respecter le split de rôle (pas de fuite admin au modérateur).
- Fenêtre figée à 24 h / barres par heure pour ce lot (pas de bascule 7 j).
