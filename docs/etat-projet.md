# État du projet Wave — 09/11/2026

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
- **Dernière vérification** (`verif.sh`, 10/11) : 382 tests backend (62 ignorés), 55 tests audio, 121 tests frontend, PHPStan propre, audit propre.

- **Test phase 2 + lot fb-1109** (09/11) : FB-13 like en 500 (table `lock_keys` absente de la base dev seulement, recréée ; like/écoute ne dépendent plus du recalcul d'affinité), FB-14 genre groupé dès 2 fichiers, FB-15 recherche de morceaux avancée (W-29a/b) et ajout depuis la page playlist. Commits `73c615b`..`7d817af`. verif.sh : 341 tests backend (62 ignorés), 55 audio, 85 frontend, audit OK.

- **Phase 3, vague 1** (10/11) : W-38 navigation (`ca66be5`), W-30 posts + migration unique des tables phase 3 (`3849acd`, appliquée en dev), revue qa 0 critique/élevé (`docs/handoff/phase3-v1-qa.md`), correctifs moyen 1 (upload borné, `b969551`) et moyen 2 + bas 4 (`719f28b`). verif.sh : 382 tests backend (62 ignorés), 55 audio, 121 frontend, audit OK.

## En cours (non commité)

- Rien. Front, points ouverts : feuille d'ajout limitée à 20 playlists, pas de bouton Suivre sur le créateur d'une playlist, glisser-déposer à tester sur mobile.

## Prochaines étapes

1. Retest par l'utilisateur (like, ajout depuis une playlist, genre groupé) ; reste non testé : écoute complète et compteur, vidéos avec et sans son, glisser-déposer sur mobile. Revue qa du lot fb-1109 faite : 0 critique/élevé, 367 tests backend.
2. Création de l'EC2 par l'utilisateur (prompt Claude for Chrome fourni), puis premier déploiement avec `infra/deploy/`.
3. **Avant la vague 2** : test de la vague 1 par l'utilisateur, et sa décision sur les posts d'un morceau privé (qa : acceptable si avertissement à la création, si repasser en privé ne retire pas les posts et si le `TrackView` exposé est réduit, `PostMapper.php:66`). Briefs vague 2 : W-32 doit vérifier que le post est jouable pour une écoute avec `postId` (moyen 3) ; W-31 purge l'objet si le post a été supprimé pendant le traitement (bas 3). Phase 5 : bas 1 (verrou du morceau à la suppression), bas 2 (ordre des verrous des hashtags), bas 5 (onglet courant ne ferme pas le lecteur), 3 index redondants avec les clés primaires (`post_hashtag`, `post_like`, `post_view`).
4. Phase 3 : posts courts, feed vertical, commentaires, signalement, notifications in-app (FB-16 : W-45, W-46). Lots : ~~W-38∥W-30~~ (fait) → W-31∥W-32∥W-39 → W-33∥W-34∥W-35∥W-45 → W-36∥W-46 → W-37.
5. Phase 4 : mesurer avec EXPLAIN sur ~100 k morceaux si l'index trigramme de la recherche de morceaux sert (moyen de la revue fb-1109, W-41), recommandations, onglet Découvrir, catalogue Deezer, recherche complète, données de démo.
6. Phase 5 : durcissement, bas niveau de la revue fb-1109 (`docs/handoff/fb-1109-qa.md` : TOCTOU à l'hydratation, erreurs 404/422 génériques, morceau de suspendu proposé dans la feuille d'ajout, demande d'affinité perdue si le process meurt), modération, suppression de compte, F18 (suspendus visibles comme propriétaires/artistes), vrais e-mails (FB-01), README et checklist de mise en prod.

## Points d'attention

- **Docker Desktop s'arrête parfois** : relancer avec `open -a Docker`, puis `make up && make init`.
- **Historique Git** : le backend de la phase 1 est en un seul commit sur GitHub. La version découpée est sur la branche locale `backend-split-commits`.
- **Avant chaque publication** : lancer `make audit` séparément, son échec ne doit pas être masqué.
- **Push** : `gh auth switch -u Nmdoiouhoma`, `git push origin main`, puis revenir sur `nakib-m` (compte de l'utilisateur pour ses autres projets).
- **/tmp du conteneur php** (tmpfs 64 Mo) : saturé le 07/11 par des fichiers de test (corrigé) ; si « No space left on device », `docker compose restart php`.
- **Ne pas lancer verif.sh pendant qu'un agent fait tourner PHPUnit** : `doctrine:database:drop` échoue.
