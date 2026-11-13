# État du projet Wave — 13/11/2026 soir (refonte + back-office faits, pause jusqu'au 15/11)

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
- **Test phase 2 + lot fb-1109** (09/11) : FB-13 like en 500 (table `lock_keys` absente de la base dev seulement, recréée ; like/écoute ne dépendent plus du recalcul d'affinité), FB-14 genre groupé dès 2 fichiers, FB-15 recherche de morceaux avancée (W-29a/b) et ajout depuis la page playlist. Commits `73c615b`..`7d817af`. verif.sh : 341 tests backend (62 ignorés), 55 audio, 85 frontend, audit OK.

- **Phase 3, vague 1** (10/11) : W-38 navigation (`ca66be5`), W-30 posts + migration unique des tables phase 3 (`3849acd`, appliquée en dev), revue qa 0 critique/élevé (`docs/handoff/phase3-v1-qa.md`), correctifs moyen 1 (upload borné, `b969551`) et moyen 2 + bas 4 (`719f28b`). verif.sh : 382 tests backend (62 ignorés), 55 audio, 121 frontend, audit OK.

- **Phase 3, lot 1b audiences FB-18** (10/11) : ADR 012 (`42d0166`), W-48 front (`a26b74c`), W-47 back (`0732061`), revue qa 0 critique/élevé, moyen 1 couvert par le test de désabonnement + règle CLAUDE.md (`28d0de8`, `docs/handoff/lot1b-qa.md`).
- **Phase 3, vague 2** (10/11) : W-31 média des posts (`2438da2`), W-39 Pour toi (`bc58185`), W-32 interactions + fil Abonnements (`b346595`). `make test` sous flock, PHPStan à 2 processus.
- **Phase 3, vague 3** (12/11) : W-33∥W-34∥W-35∥W-45 (`020f492`, `3bbd119`), correctifs revue qa (`56969b9` : `updatedAt` des notifications en microsecondes comparé exactement, signalements supprimés avec leur cible).
- **Phase 3, vague 4** (12/11) : W-36 commentaires + signalement (`fbe4d81`), W-46 cloche de notifications (`3895cc2`).
- **Phase 3, revue finale W-37** (12/11, `docs/handoff/phase3-w37-qa.md`) : 0 critique/élevé/moyen, 6 bas ; F19, F22, F23, F24 corrigés (`e076704`, `f3b487e`).
- **Test utilisateur du fil** (12/11, FB-24, `38e0eaf`) : défilement molette/touches sans focus, cœur aimé visible, retour au fil sur le même post (session gardée 10 min). Entrée sur /login bloquée par une extension, pas par l'appli.
- **Dernière vérification** (`verif.sh`, 12/11) : 479 tests backend (84 ignorés), 78 média, 219 frontend, PHPStan propre, audit propre. Tout est poussé.

- **13/11, lot ux-1113** (`9521029`) : bouton Se déconnecter dans le rail (PC) et sur son profil (téléphone) (FB-25) ; Pour toi sur PC à partir de 1024 px = post centré + panneau morceau/commentaires à droite (FB-26). verif front 223 OK.

- **13/11, refonte FB-27** (`f03e7cc`) : style « Papier » clair ET sombre (suit le système, bascule Clair/Sombre/Système dans le rail PC et Paramètres), DM Sans, maquettes `docs/design/B-*` (clair) et `D-*` (sombre). Pas encore relue à l'œil par l'utilisateur.
- **13/11, back-office admin FB-28** (ADR 013, W-70..W-77) : contrat `d35ea9c` ; journal d'activité, rôles, `app:admin:grant/revoke` (`57f0e38`) ; API admin (`bacbc20`) ; écrans tableau de bord, utilisateurs, modération, journal, alertes, annonces + bannière (`1e8359c`) ; revue W-77 0 critique/élevé (`1f5a388`), F25-F28 + F30 corrigés (`9bc00c6`) ; prod `ACTIVITY_IP_PEPPER` + contrôle d'IP client (`4abd6d8`). Compte local `nakib.dev@gmail.com` = admin. verif : 540 tests backend, 78 média, 243 frontend.
- **EC2** : t3.micro gardée (≈ 8 $/mois, choix utilisateur) + swap 2 Go créé (`/swapfile`, fstab, swappiness 10).

## En cours (non commité)

- Rien. **Pause des agents jusqu'au 15/11 1 h** : forfait de la semaine à 81 % le 13/11 à 14 h 30 (choix du lead, l'utilisateur peut lever la pause).

## Prochaines étapes

1. Utilisateur : tester la refonte (clair/sombre) et le back-office (`/admin`, se reconnecter pour le rôle) ; ses retours = lots courts Sonnet.
2. Après le 15/11 : **phase 4** sans redemander (feu vert donné le 13/11) : architect contrat catalogue Deezer (FB-06) → W-40 recommandations ∥ W-41 recherche (EXPLAIN trigramme ~100 k) ∥ W-42 démo → W-43 Découvrir → W-44 revue.
3. Premier déploiement EC2 : **la cible n'est pas couverte** : `compose.small.yaml` et `ship.sh` visent t4g.small ARM 2 Go (limites ≈ 1,76 Go) ; il faut un profil t3.micro x86 (1 Go + swap), installer Docker, suivre le bloc « Avant d'ouvrir au public » de `infra/deploy/README-deploy.md`. W-78 serveur de logs après (2e petite instance recommandée, à décider).
4. Ouverts : F29 (afficher l'agent utilisateur en texte seul, déjà le cas en React), F31 suppression de compte → purger le journal (phase 5), verrou du `suspend` W-33 comme F26 ; test du fil à 390 px sur vrai téléphone ; phase 5 (voir handoffs qa des phases 2-3, FB-01 vrais e-mails).

## Points d'attention

- **Docker Desktop s'arrête parfois** : relancer avec `open -a Docker`, puis `make up && make init`.
- **Historique Git** : le backend de la phase 1 est en un seul commit sur GitHub. La version découpée est sur la branche locale `backend-split-commits`.
- **Avant chaque publication** : lancer `make audit` séparément, son échec ne doit pas être masqué.
- **Push** : `gh auth switch -u Nmdoiouhoma`, `git push origin main`, puis revenir sur `nakib-m` (compte de l'utilisateur pour ses autres projets).
- **/tmp du conteneur php** (tmpfs 64 Mo) : saturé le 07/11 par des fichiers de test (corrigé) ; si « No space left on device », `docker compose restart php`.
- **Ne pas lancer verif.sh pendant qu'un agent fait tourner PHPUnit** : `doctrine:database:drop` échoue.
- **Agent backend et migration** : appliquer la migration en dev AVANT de mapper la colonne (l'utilisateur teste en direct, sinon toute l'API en 500).
- **Tests navigateur** : couper le son avant d'ouvrir `/` (le fil lit tout seul) ; le front doit tourner sur 8090 (médias en `Cross-Origin-Resource-Policy: same-origin`).
