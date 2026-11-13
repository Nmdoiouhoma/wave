## Fait
- ADR 013 (`docs/adr/013-admin-back-office.md`) : back-office métier dans l'appli d'abord, serveur de logs technique séparé ensuite ; matrice modérateur / admin ; admin créé par console seulement ; journal et alertes en Postgres ; dimensionnement du serveur de logs.
- DATA_MODEL : §1 (5 énumérations), §2 `last_login_at`, §13 (dé-suspension, garde de rôle, journalisation des décisions), §13b (journal à la suppression de compte), nouveaux §15 `activity_event`, §16 `security_alert` (8 règles, seuils), §17 `announcement`.
- openapi.yaml : 16 opérations `/admin/*` (tableau de bord, utilisateurs, suspension, rôle, journal, audit-log, contenus, suppression, alertes, annonces) + `GET /announcements` ; code `already_acknowledged` ; lint Redocly : 0 erreur (18 avertissements, 17 avant : description de la nouvelle étiquette, comme les autres).
- TICKETS : section « Admin back-office (before phase 4) » W-70..W-79 (backend, frontend, media-infra, qa-security, test utilisateur) ; W-50 absorbé par W-74 ; puces ajoutées à W-55 et W-54. CONVENTIONS §9 : `ACTIVITY_IP_PEPPER`.

## Décisions prises
- ROLE_ADMIN jamais par l'API : `app:admin:grant/revoke <email>` ; l'API ne fait que user ↔ moderator, jamais sur soi ni sur un admin (403).
- Modérateur : suspend seulement des utilisateurs simples ; admin : utilisateurs et modérateurs, jamais un admin (403, aussi sur la décision `suspend` W-33 : ferme le point W-37).
- Routes admin par id utilisateur (pas username) ; modérateur : email null, pas de journal / alertes / annonces / suppression.
- Vie privée : IP stockée tronquée (/24, /48) + empreinte HMAC à clé, UA 200 car., metadata sans texte utilisateur ; rétention 90 j / admin 365 j / alertes acquittées 180 j.
- Masquer / rétablir un contenu = endpoint de décision W-33 existant (marche sans signalement), pas de doublon.
- Annonces = bannière seule (pas de notification par utilisateur, trop coûteux sur 1 Go), fermeture côté client.

## Contrat d'API modifié ? (oui/non + détail)
Oui, ajouts uniquement : `/admin/*` (16 opérations), `/announcements`, `already_acknowledged`. Seul changement de comportement existant : `suspend` de `/admin/report-targets/.../decision` → 403 sur modérateur/admin selon l'appelant.

## Reste à faire / points ouverts
Questions pour l'utilisateur :
1. Rôle admin uniquement par commande console sur le serveur, jamais par l'API ? Recommandation : oui.
2. Serveur de logs : 2e petite instance (1 Go) avec Loki + Grafana, agent plafonné à 96 Mo sur la machine de l'appli, ou Dozzle seul (sans historique) ? Recommandation : 2e instance. La machine de l'appli doit de toute façon passer à 2 Go ou 2 Go d'échange.
3. Rétention 90 jours (événements) / 1 an (actions admin) / 180 jours (alertes acquittées) ? Recommandation : oui.
- Ordre : W-70 → W-71 ∥ W-72 → écrans W-73 ∥ W-74 puis W-75 (après la refonte FB-27) → W-76 ∥ W-77 → W-79 ; W-78 après le premier déploiement.
- SECURITY.md non mis à jour (menaces back-office) : à faire par W-77.

## Commande pour vérifier
`npx --yes @redocly/cli lint docs/openapi.yaml 2>&1 | tail -2` · `grep -n "^## 1[5-7]" docs/DATA_MODEL.md` · `grep -n "^\*\*W-7" docs/TICKETS.md`
