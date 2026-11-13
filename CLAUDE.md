# Wave — repères pour agents

Monorepo : `backend/` Symfony 7.4 + API Platform + Postgres + Messenger · `frontend/` React/TS/Vite ·
`infra/` + `compose*.yaml` (MinIO, FFmpeg). Code en anglais, README en français. Commandes : `make help`.

## Docs : lire par section, jamais en entier
- État courant : `docs/etat-projet.md` (à lire en premier ; index de l'état, < 150 lignes, pas un journal)
- Ticket : `grep -n "^## <ID>" docs/TICKETS.md` puis `sed -n` sur la plage
- Entité : `grep -n "^## " docs/DATA_MODEL.md` (table des sections) puis la section utile
- Endpoint : `grep -n "<path>" docs/openapi.yaml` puis ~60 lignes autour, **puis résoudre chaque `$ref`**
  (`grep -n "^    <Schema>:" docs/openapi.yaml`). Un endpoint lu sans ses schémas = contrat incomplet.
  Jamais le fichier entier (≈ 25 k tokens).
- Conventions : `docs/CONVENTIONS.md`, ta section seulement · Sécurité : `docs/SECURITY.md` · Décisions : `docs/adr/`

## Sorties bornées (contexte)
- Tests : `make test c=--no-progress 2>&1 | tail -40` · `npm test -- --reporter=dot 2>&1 | tail -40`
- Logs Docker : `docker compose logs --tail=50 <service>` ; logs Symfony : `tail -n 80 backend/var/log/dev.log`
- FFmpeg : toujours `-hide_banner -loglevel error -nostats`
- `composer install`, `npm install`, `docker compose build` : `--quiet` ou `| tail -20`
- Versions : `composer show <pkg>` / `npm ls <pkg>` ; jamais lire les lockfiles
- Jamais de `cat`/`grep -r`/`find` dans vendor/, node_modules/, var/cache/
- Captures E2E : assertions DOM ; un PNG seulement pour un bug visuel, 1-2 max par lot

## Qualité (non négociable)
- Un hook lance PHPStan + cs-fixer + PHPUnit (backend) et typecheck + lint + Vitest (frontend) à la fin de
  chaque agent qui a écrit dans le périmètre. Un échec renvoie la sortie : corriger, ne jamais contourner.
- Avant commit d'un lot : `make qa` complet (inclut test-media et audit, non lancés par le hook).
- Migrations Doctrine : relues par le lead (SQL généré affiché) avant toute application.
- Une migration exécutée quelque part (même en dev) ne se modifie plus : toute correction = nouvelle migration.
- `doctrine:schema:update --dump-sql` propose de supprimer des objets écrits à la main (index de recherche, `uniq_notification_unread`, `fk_close_friend_follow`, `idx_activity_event_*`, `uniq_security_alert_open_subject`, `idx_security_alert_status`, colonne générée `security_alert.subject_key`…) : ne jamais reprendre ces DROP dans une migration générée.
- Tout agent qui écrit du code, qa-security compris (tests), lance `wave-verif` ciblé avant de rendre la main.
- Retour utilisateur sur un affichage : localiser d'abord le composant qui affiche la donnée, avant API ou base.

## Organisation
- Tâche de moins de ~15 appels : le lead la fait lui-même, sans agent, **si son contexte est sous ~80 k tokens** ;
  au-delà, le lead diagnostique et confie le correctif précis (fichiers, lignes) à un agent court (Sonnet si non sensible).
- Une session de lead par vague : en fin de vague, état écrit et commité, puis l'utilisateur fait `/clear` et « reprends Wave ».
- Modèles : Sonnet par défaut pour les écrans ; Opus pour auth, autorisations, upload, FFmpeg et revues qa. Essai phase 4 :
  un écran Sonnet qui demande plus d'une série de correctifs après revue → ce type d'écran repasse sur Opus.
- Tests navigateur : l'utilisateur les fait dans une autre session (prompt fourni) ; le lead ne fait qu'un contrôle ciblé.
- Une vague de lots se termine par un test manuel de l'utilisateur avant la vague suivante.
- Revue qa-security : diff + fichiers touchés + handoff ; ouvrir les fichiers voisins (voters, `security:` des
  opérations API Platform, config). Vérifier surtout ce qui n'est pas testé (accès refusé, entrée invalide, erreurs).

## Passation
- Fin de lot : `docs/handoff/<lot>-<role>.md` selon le gabarit `docs/handoff/README.md` (≤ 30 lignes).
- Retour au lead en une ligne, qui dit explicitement si une décision ou un changement de contrat est à lire.

## Invariants
- PHP épinglé `php:8.4.27-fpm` minimum (PHP2Shell) ; CI en échec sous 8.4.27
- FFmpeg via `Process` avec tableau d'arguments, jamais une chaîne ; aucun bucket MinIO public par accident
