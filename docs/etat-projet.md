# État du projet Wave — 30/10/2026

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

**Infra**
- Ports locaux : 8090 (app), 8027 (Mailpit).
- MinIO via un fork communautaire, réservé au développement.
- Hébergement visé : offre gratuite AWS, t4g.small (2 Go, ARM).

## Fait

- **Phase 0** : architecture (`DATA_MODEL`, `openapi.yaml`, `CONVENTIONS`, ADR 001-009, `TICKETS`).
- **Phase 1** : Docker, comptes, profils, artistes, upload, traitement FFmpeg, streaming, lecteur global. Revue qa-security : aucun problème ouvert.
- **Lot de retours FB-01 à FB-10** (`docs/FEEDBACK.md`) : tous traités sauf FB-01 (vrais e-mails, prévu avant la mise en prod).
- **Dernière vérification** : 276 tests backend (dont plus de 100 de sécurité), 55 tests audio, 59 tests frontend ; audits Composer et npm propres.

## En cours (non commité)

- **Phase 2, backend** (W-20 à W-23, W-27) : playlists, suivi, likes, écoutes, recherche d'utilisateurs. Agent interrompu par la limite d'usage pendant l'écriture des tests, à relancer.
- **Phase 2, frontend** (W-24, W-25, W-28) : bibliothèque, profil social, écran de recherche. Agent interrompu, à relancer.
- **Déploiement, media-infra (terminé, à vérifier puis commiter)** :
  - `compose.small.yaml` : configuration allégée pour 2 Go ; un MP4 de 200 Mo a été traité en local avec ces limites.
  - `infra/deploy/` : scripts d'installation et d'envoi des images, certificat HTTPS, `README-deploy.md`.
  - Pas encore testé sur un vrai serveur Ubuntu.

## Prochaines étapes

1. Relancer backend et frontend, vérifier leur travail, puis commiter la phase 2.
2. Revue de sécurité de la phase 2 (W-26), puis test par l'utilisateur.
3. Création de l'EC2 par l'utilisateur (prompt Claude for Chrome fourni), puis premier déploiement avec `infra/deploy/`.
4. Phase 3 : posts courts, feed vertical, commentaires, signalement.
5. Phase 4 : recommandations, onglet Découvrir, catalogue Deezer, recherche complète, données de démo.
6. Phase 5 : durcissement, modération, suppression de compte, vrais e-mails (FB-01), README et checklist de mise en prod.

## Points d'attention

- **Docker Desktop s'arrête parfois** : relancer avec `open -a Docker`, puis `make up && make init`.
- **Historique Git** : le backend de la phase 1 est en un seul commit sur GitHub. La version découpée est sur la branche locale `backend-split-commits`.
- **Avant chaque publication** : lancer `make audit` séparément, son échec ne doit pas être masqué.
