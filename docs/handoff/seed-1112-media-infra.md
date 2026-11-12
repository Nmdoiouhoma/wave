## Fait
- `make seed` (Makefile) : refus si `APP_ENV` != dev, puis `app:seed` dans le conteneur `worker` (seul avec ffmpeg). Mesuré : 241 s (rejouée 3 fois, idempotente), exit 0.
- `backend/src/Command/SeedDevCommand.php` (+ `SeedProcessTrackCommand.php`, `Seed/{AudioSynth,ImageSynth,Catalog,SeedRng}.php`) : TRUNCATE de toutes les tables sauf `genre`, `doctrine_migration_versions`, `lock_keys` ; purge des 2 buckets ; 10 comptes, 12 artistes, 43 morceaux, 8 playlists, 16 posts, graphe social, compteurs recalculés en SQL.
- Audio 100 % FFmpeg (aevalsrc, 17 styles, tempos et durées variés) ; pas d'image ni de son externe ; avatars/pochettes/visuels en dégradés + initiales.
- Pipeline réel : les handlers `ProcessTrack` (8 processus en parallèle), `ProcessImage`, `GenerateClip`, `ProcessPostVisual` sont appelés tels quels : mêmes objets et colonnes que le worker.
- Vérifié : SQL (10 comptes vérifiés, 43 morceaux ready, 16 posts ready), HTTP (stream 206 pour les ayants droit, 404 pour ines sur le followers/close_friends d'amina, hugo sur le close_friends). PHPStan et cs-fixer OK.
- Détail des comptes et audiences : `docs/handoff/seed-1112.md`.

## Décisions prises
- Code placé dans `backend/src/Command` (hors périmètre strict : il faut le noyau Symfony), compilé aussi dans l'image prod mais inerte : garde `kernel.environment === 'dev'`.
- Pas de ligne `track_upload` ; identifiants user/artiste/playlist en uuid v7 aléatoires (contenu déterministe, id non).
- Correctif d'un bug réel hors périmètre : `PostVisualProcessor::poster()` n'avait pas `-threads 2` ; sur un hôte à 8 cœurs l'extraction d'affiche des vidéos de post échouait (ENOMEM sous `prlimit --as 768M`). Une ligne ajoutée, comme l'encodage.

## Contrat d'API modifié ?
Non. Aucune migration.

## Reste à faire / points ouverts
- Le lead relit la ligne ajoutée à `PostVisualProcessor` (backend) ; lancer `make test-media` avant commit.
- Marge de 59 s sur 5 min : la phase `loudnorm` du worker (35 s par morceau en parallèle) domine.

## Commande pour vérifier
`make seed` puis `docker compose exec -T postgres psql -U wave -d wave -c "select count(*) from track where status='ready'"` (43)
