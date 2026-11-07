# État du projet Wave — 07/11/2026

Dépôt : https://github.com/Nmdoiouhoma/wave (privé, monorepo). Lancement local : `make up && make init` → http://localhost:8090 (e-mails dans Mailpit : http://localhost:8027).

## Décisions prises

**Cadrage (D1-D7, `docs/BRIEF.md`)**
- Symfony 7.4 LTS ≥ 7.4.13, PHP ≥ 8.4.27 épinglé par digest (faille PHP2Shell).
- Audio servi par URL signée de 5 min, nginx gère les requêtes Range.
- Refresh token : cookie httpOnly sur le web, renvoyé dans la réponse sur mobile.
- Entité Artist à part entière ; un post peut réutiliser le morceau d'un autre si celui-ci est public et que son auteur l'autorise (`allowClips`).
- Compte obligatoire pour tout voir ; vérification d'e-mail, mot de passe oublié et suppression de compte.
- Supprimer un morceau supprime aussi les posts qui l'utilisent.

**Pendant les tests**
- Upload direct vers le stockage, sans passer par PHP (ADR 009) : MP4 jusqu'à 200 Mo (le son est extrait, la vidéo jetée), MP3/AAC 30 Mo.
- Création d'artiste en 2 étapes, dont l'ajout de plusieurs morceaux d'un coup.
- 45 genres en français, rap en tête.
- Tutoiement dans toute l'interface ; e-mails en français.
- Option « extraits » affichée seulement pour un morceau public.
- Motif de refus `no_audio` pour les vidéos sans son.
- Révocation de toute la session si un ancien jeton de renouvellement est réutilisé (ADR 008).
- Artistes célèbres via l'API Deezer (extraits de 30 s, usage non commercial), prévu en phase 4.
- La recherche d'utilisateurs est avancée en phase 2.
- Sécurité phase 2 (W-26) : une écoute ne compte qu'avec une stream-url récente (ADR 010, 20/jour par titre) ; 200 playlists max ; comptes suspendus → 404 ; unlike sans like sur cible invisible → 404 (pas d'oracle d'existence).

**Infra**
- Ports locaux : 8090 (app), 8027 (Mailpit).
- MinIO via un fork communautaire, réservé au développement.
- Hébergement visé : offre gratuite AWS, t4g.small (2 Go, ARM).

## Fait

- **Phase 0** : architecture (`DATA_MODEL`, `openapi.yaml`, `CONVENTIONS`, ADR 001-009, `TICKETS`).
- **Phase 1** : Docker, comptes, profils, artistes, upload, traitement FFmpeg, streaming, lecteur global. Revue qa-security : aucun problème ouvert.
- **Lot de retours FB-01 à FB-10** (`docs/FEEDBACK.md`) : tous traités sauf FB-01 (vrais e-mails, prévu avant la mise en prod).
- **Phase 2** (W-20 à W-25, W-27, W-28) : playlists avec glisser-déposer, abonnements, likes, écoutes, recherche d'utilisateurs. Commits `38af9ba`..`4f92612`, poussés. Symfony 7.4.22 (CVE-2026-45070).
- **Skills projet** (`.claude/skills/wave-*`) : stack, verif, commit, lot, retour, etat, ameliorer ; chacun apprend via son `lessons.md`.
- **Correctifs W-26** (F10-F17, revue sécurité phase 2 + re-revue) : commits `741829c`..HEAD, poussés. Symfony 7.4.23 (CVE-2026-118513..118516), table `listen_grant`, suites de sécurité sociale et concurrence. F18 (suspendu visible comme propriétaire/artiste) reporté en phase 5.
- **Dernière vérification** (`verif.sh`, 07/11) : 333 tests backend (62 ignorés), 55 tests audio, 79 tests frontend, PHPStan propre, audit propre.

## En cours (non commité)

- Rien. Front, points ouverts : feuille d'ajout limitée à 20 playlists, pas de bouton Suivre sur le créateur d'une playlist, glisser-déposer à tester sur mobile.

## Prochaines étapes

1. Test de la phase 2 par l'utilisateur.
2. Création de l'EC2 par l'utilisateur (prompt Claude for Chrome fourni), puis premier déploiement avec `infra/deploy/`.
3. Phase 3 : posts courts, feed vertical, commentaires, signalement.
4. Phase 4 : recommandations, onglet Découvrir, catalogue Deezer, recherche complète, données de démo.
5. Phase 5 : durcissement, modération, suppression de compte, F18 (suspendus visibles comme propriétaires/artistes), vrais e-mails (FB-01), README et checklist de mise en prod.

## Points d'attention

- **Docker Desktop s'arrête parfois** : relancer avec `open -a Docker`, puis `make up && make init`.
- **Historique Git** : le backend de la phase 1 est en un seul commit sur GitHub. La version découpée est sur la branche locale `backend-split-commits`.
- **Avant chaque publication** : lancer `make audit` séparément, son échec ne doit pas être masqué.
- **Push** : `gh auth switch -u Nmdoiouhoma`, `git push origin main`, puis revenir sur `nakib-m` (compte de l'utilisateur pour ses autres projets).
- **/tmp du conteneur php** (tmpfs 64 Mo) : saturé le 07/11 par des fichiers de test (corrigé) ; si « No space left on device », `docker compose restart php`.
- **Ne pas lancer verif.sh pendant qu'un agent fait tourner PHPUnit** : `doctrine:database:drop` échoue.
