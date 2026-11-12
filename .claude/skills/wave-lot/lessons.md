# Leçons wave-lot (les plus récentes en haut, 15 max)
<!-- Format : AAAA-MM-JJ · symptôme → cause → règle. Une leçon intégrée au SKILL.md ou au script est retirée d'ici. -->
- 2026-11-10 · 3 agents en parallèle : PHPStan et PHPUnit tués (code 137), base de test supprimée en cours → conteneur php à 512 Mo + hooks sans verrou → PHPStan à 2 processus, `make test` sous flock, et la vérification complète du lot se lance par le lead seul, après le dernier retour.
- 2026-11-07 · contrat appliqué à la lettre créait un oracle d'existence (F14) → l'architecte n'avait pas croisé avec T17 → dans le brief architect, demander de vérifier chaque règle contre les tests de sécurité existants (SECURITY.md T*).
- 2026-11-01 · lecture du handoff backend = 150 lignes de SQL → annexe de migrations en fin de fichier → lire `sed -n "1,/^## Annexe/p"` ; le SQL ne se lit qu'à la relecture des migrations.
- 2026-11-01 · découpage des commits sans `git add -p` (non interactif) → fichiers partagés rangés dans le premier commit de fonctionnalité qui en dépend → acceptable, le signaler dans le corps du commit.
