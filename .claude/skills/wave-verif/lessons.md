# Leçons wave-verif (les plus récentes en haut, 15 max)
<!-- Format : AAAA-MM-JJ · symptôme → cause → règle. Une leçon intégrée au SKILL.md ou au script est retirée d'ici. -->
- 2026-11-01 · `Test database setup failed: doctrine:database:drop` → suite de l'agent encore en cours → intégré : verif.sh attend la fin de toute autre suite PHPUnit.
- 2026-10-31 · audit composer en échec (symfony/mime CVE-2026-45070) alors que PHP/Symfony « OK » → audit-symfony ne surveille que security-http/http-kernel → lire la ligne `Package` ; montée de version par le backend.
- 2026-10-31 · npm audit liste 15 vulnérabilités « moderate » → seuil `--audit-level=high` → elles ne bloquent pas, ne pas lancer `npm audit fix --force` (casse eslint).
- 2026-10-27 · audit en échec poussé quand même → `make qa | grep | tail` masque le code retour → jamais de tuyau sur une commande de barrière.
