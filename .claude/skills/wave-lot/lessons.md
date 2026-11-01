# Leçons wave-lot (les plus récentes en haut, 15 max)
<!-- Format : AAAA-MM-JJ · symptôme → cause → règle. Une leçon intégrée au SKILL.md ou au script est retirée d'ici. -->
- 2026-11-01 · lecture du handoff backend = 150 lignes de SQL → annexe de migrations en fin de fichier → lire `sed -n "1,/^## Annexe/p"` ; le SQL ne se lit qu'à la relecture des migrations.
- 2026-11-01 · découpage des commits sans `git add -p` (non interactif) → fichiers partagés rangés dans le premier commit de fonctionnalité qui en dépend → acceptable, le signaler dans le corps du commit.
- 2026-10-30 · backend et frontend coupés par la limite d'usage en pleine écriture des tests → travail non commité dans l'arbre → relancer par SendMessage, puis `wave-verif` avant de croire le rapport.
- 2026-10-27 · un chiffre de tests d'agent relayé faux → rapports d'agents optimistes → ne relayer que les chiffres de `verif.sh`.
- 2026-10-27 · agents en parallèle sur la même base de test → suites sécu et média concurrentes → jamais deux suites PHPUnit en même temps.
