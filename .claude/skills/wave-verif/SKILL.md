---
name: wave-verif
description: Barrière qualité d'un lot Wave avant commit, en une commande au résultat compact — PHPStan, cs-fixer, PHPUnit, tests média, typecheck/lint/Vitest/build front, synchro du contrat OpenAPI, audit de sécurité. À utiliser après le retour d'un agent, avant /wave-commit, ou quand l'utilisateur dit « vérifie le lot ».
argument-hint: "[front|back|all]"
---

# Vérifier un lot Wave

1. Lancer `bash .claude/skills/wave-verif/verif.sh $ARGUMENTS` (sans argument : détecte front/back d'après `git status`). Sortie : une ligne par étape, les 25 dernières lignes du log seulement en cas d'échec, puis `VERDICT OK|FAIL`. Ne jamais la passer dans un tuyau (`| grep`, `| tail`) : le code de retour doit rester visible.
2. `FAIL` : lire le log indiqué par `grep`/`sed -n`, jamais en entier. Corriger via l'agent propriétaire (SendMessage s'il est encore en vie), pas moi-même hors de mon périmètre de lead.
   - `check-api` : `schema.d.ts` désynchronisé → `npm run gen:api` par le frontend.
   - `audit` : nouvel avis de sécurité → monter la version (le backend possède `composer.json`).
3. `INFO migrations` : afficher le SQL (`--dry-run`) et le relire — pas de `DROP` inattendu, index sur les clés étrangères.
4. Périmètres : chaque fichier de `git status --short` appartient au propriétaire attendu (`docs/BRIEF.md`).
5. Rendu : les lignes OK/FAIL telles quelles. Seuls ces chiffres mesurés se relaient, jamais ceux d'un rapport d'agent.

## Apprendre (à chaque usage)
- **Avant** : lire `.claude/skills/wave-verif/lessons.md` (court) et appliquer ses règles.
- **Après** : erreur rencontrée, contournement trouvé, correction ou préférence de l'utilisateur, étape inutile → ajouter **une** ligne en haut de `lessons.md` (`AAAA-MM-JJ · symptôme → cause → règle`). Rien de neuf → ne rien écrire.
- Une leçon vue 2 fois ou qui touche une commande → l'intégrer ici ou dans le script, la retirer de `lessons.md`, et le dire en une ligne à l'utilisateur.
