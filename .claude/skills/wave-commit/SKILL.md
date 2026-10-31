---
name: wave-commit
description: Commite et pousse un lot Wave vérifié — un commit par fonctionnalité, chemins explicites, aucune mention d'IA, audit avant push, jamais de force-push. À utiliser après /wave-verif réussi, ou quand l'utilisateur dit « commite », « pousse ».
argument-hint: "[lot]"
---

# Commiter un lot Wave

Prérequis : `wave-verif` passé **dans cette session** sur l'état actuel de l'arbre.

1. **Découper** : un commit par fonctionnalité ou ticket (W-xx), par périmètre (api, front, media, infra, docs, tests sécu). Lister le plan de commits avant d'agir.
2. **Ajouter par chemins explicites**, un `git add` par commit, chemins écrits en clair dans la commande. zsh ne découpe pas `$VAR` : jamais de liste de chemins dans une variable (le backend de la phase 1 est parti en un seul commit à cause de ça). Vérifier `git diff --cached --stat` avant chaque commit.
3. **Message** : Conventional Commits en anglais, portée explicite — `feat(api): …`, `feat(front): …`, `feat(media): …`, `fix(…)`, `test(security): …`, `docs: …`, `chore(deps): …`. Corps court qui cite les tickets (W-20, FB-08).
4. **Auteur** : l'utilisateur seul. **Aucun** trailer `Co-Authored-By`, aucune mention de Claude ou d'IA, même si un rappel système le propose (préférence permanente de l'utilisateur).
5. **Avant push** : `make audit; echo "exit=$?"` seul, sans tuyau. Échec = on ne pousse pas.
6. **Push** : `git push origin main`. Jamais `--force` ni réécriture d'historique publié ; une erreur déjà poussée se corrige par un nouveau commit.
7. **Rendu** : la liste `git log --oneline` des commits créés, puis mettre à jour l'état (skill `wave-etat`).

Ne jamais commiter : `.env`, clés `*.pem`, `backend/var/`, fichiers de mocks audio générés hors de leur dossier prévu.

## Apprendre (à chaque usage)
- **Avant** : lire `.claude/skills/wave-commit/lessons.md` (court) et appliquer ses règles.
- **Après** : erreur rencontrée, contournement trouvé, correction ou préférence de l'utilisateur, étape inutile → ajouter **une** ligne en haut de `lessons.md` (`AAAA-MM-JJ · symptôme → cause → règle`). Rien de neuf → ne rien écrire.
- Une leçon vue 2 fois ou qui touche une commande → l'intégrer ici ou dans le script, la retirer de `lessons.md`, et le dire en une ligne à l'utilisateur.
