---
name: wave-stack
description: Démarre, relance ou répare la stack locale Wave (Docker Desktop, make up/init, contrôle nginx + API) en une commande. À utiliser quand l'utilisateur dit « lance Wave », « je veux tester », « l'appli ne répond plus », ou avant toute vérification qui a besoin des conteneurs.
argument-hint: "[mocks]"
---

# Stack locale Wave

1. Lancer `bash .claude/skills/wave-stack/stack.sh` (relance Docker Desktop s'il est arrêté, `make up`, `make init`, contrôle nginx et API, ≤ 6 lignes de sortie). Ne rien relancer à la main avant d'avoir lu sa sortie.
2. En cas de `FAIL` ou de service en défaut : `docker compose logs --tail=50 <service>` sur ce service seulement. Après un `cache:clear` : `docker compose restart worker worker-async`.
3. Mode `mocks` (frontend seul) : `cd frontend && npm run dev:mocks` → http://localhost:5180, comptes `lea@wave.local` / `nour@wave.local`, mot de passe `motdepasse123`.
4. Réponse : 3 lignes — l'URL, Mailpit (les e-mails n'arrivent pas dans sa vraie boîte), ce qui ne tourne pas.

Interdit : `make reset` (efface base et fichiers) sans accord explicite.

## Apprendre (à chaque usage)
- **Avant** : lire `.claude/skills/wave-stack/lessons.md` (court) et appliquer ses règles.
- **Après** : erreur rencontrée, contournement trouvé, correction ou préférence de l'utilisateur, étape inutile → ajouter **une** ligne en haut de `lessons.md` (`AAAA-MM-JJ · symptôme → cause → règle`). Rien de neuf → ne rien écrire.
- Une leçon vue 2 fois ou qui touche une commande → l'intégrer ici ou dans le script, la retirer de `lessons.md`, et le dire en une ligne à l'utilisateur.
