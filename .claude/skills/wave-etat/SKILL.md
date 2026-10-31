---
name: wave-etat
description: Met à jour docs/etat-projet.md et la mémoire projet Wave en fin de lot, de session ou avant une pause, ou relit l'état pour reprendre. À utiliser quand l'utilisateur dit « on reprendra », « résume où on en est », « reprends Wave », ou après un commit de lot.
argument-hint: "[reprise]"
---

# État du projet Wave

**Mode `reprise`** (ou « reprends Wave », « où on en est ») : lire `docs/etat-projet.md`, `git status --short | head -30`, `git log --oneline -5`, `ls docs/handoff/`. Répondre en 10 lignes max : où on en est, la prochaine action, ce qui attend l'utilisateur. Ne rien écrire.

**Mode écriture** (défaut) :
1. Réécrire `docs/etat-projet.md` en place — c'est un index de l'état, pas un journal, < 150 lignes. Rubriques fixes : titre daté, Décisions prises, Fait (une ligne par phase/lot, chiffres de tests mesurés), En cours (non commité), Prochaines étapes (numérotées), Points d'attention. Le détail va dans les commits et les handoffs, pas ici.
2. Mettre à jour la mémoire `projet-wave` : une ligne datée par jalon (commits, décisions de l'utilisateur, ce qui attend qui), sans recopier le fichier d'état.
3. Commiter `docs/etat-projet.md` seul (`docs: update project status`), selon le skill `wave-commit` (pas de push si l'audit échoue).
4. Réponse : une ligne avec le commit.

Interdits : aucun secret (mots de passe, clés, jetons) ni contenu du `.env` dans l'état.
