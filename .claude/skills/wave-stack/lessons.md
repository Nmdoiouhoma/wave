# Leçons wave-stack (les plus récentes en haut, 15 max)
<!-- Format : AAAA-MM-JJ · symptôme → cause → règle. Une leçon intégrée au SKILL.md ou au script est retirée d'ici. -->
- 2026-11-01 · migrations de la phase 2 appliquées en dev avant relecture → `make init` migre automatiquement → s'il y a des migrations non commitées, relire leur SQL avant de lancer stack.sh.
- 2026-10-26 · worker qui redémarre en boucle → base dev non migrée → `make init` avant de chercher plus loin.
- 2026-10-26 · workers plantés après `cache:clear` → cache partagé avec php → `docker compose restart worker worker-async`.
- 2026-10-26 · l'utilisateur ne reçoit pas l'e-mail de vérification → il arrive dans Mailpit :8027, pas dans Gmail → toujours rappeler Mailpit.
