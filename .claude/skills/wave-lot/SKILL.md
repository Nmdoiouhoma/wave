---
name: wave-lot
description: Lance et pilote un lot ou une phase Wave avec l'équipe d'agents (wave-architect, wave-backend, wave-frontend, wave-media-infra, wave-qa-security) — briefs, ordre, relais, revue, fin de phase. À utiliser quand l'utilisateur dit « lance la phase N », « reprends le lot », ou après un feu vert de sa part.
argument-hint: "<phase ou lot, ex. phase3 | W-30..W-33> [reprise]"
---

# Piloter un lot Wave

Je suis le lead : les agents ne se parlent pas, je relaie tout et je suis seul à commiter.

1. **Cadrer** : lire `docs/etat-projet.md`, puis les tickets du lot (`grep -n "^## Phase\|^\*\*W-" docs/TICKETS.md`, puis `sed -n` sur la plage). **Phase nouvelle = présenter plan + tickets à l'utilisateur et attendre son feu vert** avant que quiconque code (exigence du projet).
2. **Contrat d'abord** : si le lot ajoute ou change un endpoint, `wave-architect` passe seul en premier (openapi + DATA_MODEL + ADR si décision). Les autres partent après son commit `docs:`.
3. **Lancer en parallèle** les agents dont les périmètres ne se croisent pas (table de `docs/BRIEF.md`), en arrière-plan, un agent éphémère par lot. Brief type, court :
   - lot et tickets (IDs), sections de doc à lire (chemins + `grep`), handoffs précédents à lire (chemins, pas leur contenu) ;
   - décisions de l'utilisateur à respecter, relais d'autres agents à traiter ;
   - rappel : périmètre exclusif, handoff `docs/handoff/<lot>-<role>.md`, retour en une ligne, ne pas commiter.
   - `wave-frontend` en `model: sonnet` seulement pour un lot de mise en page sans logique d'auth ou de lecture.
4. **Pendant le lot** : chaque retour qui signale une question de contrat part chez `wave-architect`, jamais tranché par l'agent demandeur. Agent coupé (limite d'usage, coupure de session) → attendre que `frontend/`/`backend/` ne bougent plus (dates de modification), puis le relancer avec SendMessage (« reprends où tu t'es arrêté, relis ton handoff et `git status` ») ; si l'ancien a pu continuer en parallèle, un seul agent neuf par ticket qui reprend l'ébauche. Puis `wave-verif` avant de croire son rapport.
5. **Vérifier puis commiter** : skill `wave-verif`, puis `wave-commit`. Ne jamais relayer un chiffre d'agent sans l'avoir mesuré.
6. **Revue** : `wave-qa-security` sur le lot commité (Opus si auth, autorisations, upload, FFmpeg ; sinon `model: sonnet`). Findings critique/élevé/moyen → correctifs par les propriétaires, re-vérif, re-revue ciblée. Bas niveau → noté pour la phase 5.
7. **Fin de lot ou de phase** : skill `wave-ameliorer` (fin de phase seulement), skill `wave-etat`, puis résumé à l'utilisateur — ce qui marche, comment tester (URL, compte, parcours en 3-5 étapes), ce qui reste. Notification push seulement si j'ai besoin de lui (test navigateur, décision).
8. **Fin de vague** : après `wave-etat`, terminer le message par ces étapes, toujours écrites ainsi :
   « Pour la suite : 1. tape `/clear` ; 2. tape `reprends Wave`. » (une session de lead par vague, économie de tokens).

Mode `reprise` : `git status --short`, `ls docs/handoff/`, dernière section « En cours » de `docs/etat-projet.md`, puis reprendre à l'étape où le lot s'est arrêté.

## Apprendre (à chaque usage)
- **Avant** : lire `.claude/skills/wave-lot/lessons.md` (court) et appliquer ses règles.
- **Après** : erreur rencontrée, contournement trouvé, correction ou préférence de l'utilisateur, étape inutile → ajouter **une** ligne en haut de `lessons.md` (`AAAA-MM-JJ · symptôme → cause → règle`). Rien de neuf → ne rien écrire.
- Une leçon vue 2 fois ou qui touche une commande → l'intégrer ici ou dans le script, la retirer de `lessons.md`, et le dire en une ligne à l'utilisateur.
