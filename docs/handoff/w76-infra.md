# W-76 · media-infra · Prod wiring of the activity journal

## Fait
- `ACTIVITY_IP_PEPPER` câblé : `compose.yaml` (`:?`, php + workers), `.env.example` (`change-me`), `Makefile` (`make env` le génère), `infra/deploy/gen-env.sh` (64 hex, testé), `infra/php/Dockerfile` (valeur factice du warmup et d'assets:install). Ce blocage de w70-backend est levé.
- Scheduler : rien à changer. `worker-async` consomme `scheduler_default` (1 réplique) et `WaveSchedule` planifie `PurgeActivityEvents` à 03:55 UTC.
- `TRUSTED_PROXIES` : reste vide (nginx est le bord, `snippets/fpm.conf` réécrit `X-Forwarded-For` avec `$remote_addr`, vide `Forwarded`/`X-Real-IP`/`Client-IP`). Vérifié en réel : `infra/scripts/check-client-ip.sh` envoie un login raté avec `X-Forwarded-For: 203.0.113.77` via nginx, lit `activity_event.ip_prefix` -> `192.168.65.0/24` (adresse réelle), OK.
- `docs/CONVENTIONS.md` §9 corrigé : `TRUSTED_PROXIES` valait `172.16.0.0/12` (contredisait F1 et le compose) -> vide.
- `README-deploy.md` : bloc « Avant d'ouvrir au public » (admin via `app:admin:grant`, rétention 90/365/180 j, phrase de confidentialité, contrôle IP).

## Décisions / contrat
- Changement d'env : `ACTIVITY_IP_PEPPER` devient obligatoire dans `.env` (compose refuse sans). J'ai ajouté une valeur aléatoire au `.env` local existant ; tout autre `.env` doit l'avoir.
- Le pepper ne doit pas tourner une fois en prod (corrélation d'IP perdue).

## À lire / ouvert
- Cible annoncée t3.micro x86 (1 Go + 2 Go swap) mais `compose.small.yaml` et `ship.sh` sont dimensionnés pour t4g.small arm64 2 Go (limites cumulées ~1,76 Go) : aucun `compose.micro` créé, aucune limite ajoutée ; à trancher avant le déploiement (images amd64, limites réduites).
- Le contrôle IP n'est pas dans la CI (nécessite la pile Docker démarrée).

## Vérif
- `verif.sh all` : voir réponse du lead.
