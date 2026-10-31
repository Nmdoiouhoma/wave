---
name: wave-ameliorer
description: Revue des skills Wave — consolide les leçons accumulées (lessons.md), l'historique des vérifications (history.tsv) et les retours de l'utilisateur en modifications concrètes des skills et scripts. À utiliser en fin de phase (appelé par wave-lot), quand un `RECURRENT` sort de verif.sh, ou quand l'utilisateur dit « améliore les skills ».
---

# Améliorer les skills Wave

Objectif unique : moins de temps et moins de tokens au prochain usage.

1. **Collecter** (sorties courtes) : `wc -l .claude/skills/*/lessons.md`, `cat .claude/skills/*/lessons.md`, `tail -20 .claude/skills/wave-verif/history.tsv`, et les corrections de l'utilisateur dans la session.
2. **Trier chaque leçon** :
   - récurrente ou mécanique → l'automatiser dans le script (`stack.sh`, `verif.sh`) ou en faire une étape du SKILL.md ;
   - règle de conduite durable → une ligne dans la section du SKILL.md concerné ;
   - ponctuelle, déjà corrigée dans le code → supprimer ;
   - étape d'un skill jamais utile en pratique → la retirer (un skill plus court coûte moins).
3. **Mesurer le coût** : un script dont la sortie dépasse ~30 lignes en cas normal → la compacter. Un skill > 40 lignes → le resserrer.
4. **Appliquer** les modifications, `bash -n` sur les scripts modifiés, puis un essai réel du script si la stack tourne.
5. **Commiter** (`chore(skills): …`, skill `wave-commit`).
6. **Rendu** : 5 lignes max — ce qui a changé, l'économie attendue (étape supprimée, sortie réduite), les leçons restantes.

## Apprendre (à chaque usage)
- Ce skill n'a pas de `lessons.md` : ses propres ajustements se font directement ici.
