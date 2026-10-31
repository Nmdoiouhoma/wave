---
name: wave-retour
description: Consigne un retour de test de l'utilisateur sur Wave dans docs/FEEDBACK.md (FB-xx), le qualifie (bug, manque, idée), l'attribue à un propriétaire et à une phase. À utiliser quand l'utilisateur décrit un problème ou une envie après avoir testé l'appli (« j'ai un problème quand… », « il manque… », capture d'écran d'erreur).
argument-hint: "<description du retour>"
---

# Retour de test Wave

1. **Comprendre avant d'écrire** : reproduire si possible (logs bornés : `docker compose logs --tail=50 <service>`, `tail -n 80 backend/var/log/dev.log` ; fichier fourni → `ffprobe -hide_banner -loglevel error -show_streams`). Dire en 2-3 lignes la cause réelle, sans jargon.
2. **Qualifier** : bug (contraire au contrat ou au BRIEF) / incohérence d'interface / fonctionnalité manquante. Une fonctionnalité déjà planifiée → citer la phase et le ticket au lieu d'en créer un.
3. **Décision nécessaire ?** S'il y a un vrai choix produit (limite, comportement), poser **une** question à choix avec mon option recommandée en premier et une option « comme l'existant ». Donner mon avis quand il le demande.
4. **Consigner** : nouvelle ligne dans le tableau de `docs/FEEDBACK.md` — `FB-<n+1>`, date du jour (AAAA-MM-JJ), retour reformulé en anglais avec la décision prise, propriétaire(s), cible (lot de retours en cours, phase N, avant prod).
5. **Router** : bug bloquant pour ses tests → lot de retours immédiat (skill `wave-lot`) ; le reste rejoint la phase cible. Contrat touché → `wave-architect` d'abord.
6. **Réponse** : 5 lignes max — cause, ce qui sera fait, quand.

## Apprendre (à chaque usage)
- **Avant** : lire `.claude/skills/wave-retour/lessons.md` (court) et appliquer ses règles.
- **Après** : erreur rencontrée, contournement trouvé, correction ou préférence de l'utilisateur, étape inutile → ajouter **une** ligne en haut de `lessons.md` (`AAAA-MM-JJ · symptôme → cause → règle`). Rien de neuf → ne rien écrire.
- Une leçon vue 2 fois ou qui touche une commande → l'intégrer ici ou dans le script, la retirer de `lessons.md`, et le dire en une ligne à l'utilisateur.
