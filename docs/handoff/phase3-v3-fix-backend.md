# Phase 3 vague 3 — correctifs backend de la revue qa (moyens 1 et 2)

## Fait
- Moyen 1 : `DateFormat::formatMillis` (ébauche ms non commitée) remplacé par `formatMicros` (`Y-m-d\TH:i:s.uP`, UTC → `+00:00`), utilisé seulement par `NotificationListProvider` ; `DateFormat::format` des autres réponses inchangé. `NotificationRepository::markRead` compare exactement `updated_at <= :upTo` (plus de `date_trunc`), `upTo` lié avec ses µs. L'entrée accepte déjà 0 à 6 décimales (`MarkNotificationsReadInput::PATTERN`) : `…00Z`, `….700Z`, `….700000+00:00` testés.
- Moyen 2 : `ReportRepository::deleteForTarget(type, id)` appelé dans la transaction de suppression de `PlaylistProcessor` (suppression maintenant enveloppée dans `wrapInTransaction`) et d'`ArtistProcessor`.
- Morceau : déjà propre (`PostCleanup::beforeTrackDeletion` : signalements du morceau, de ses posts et de leurs commentaires). Commentaire et post : déjà propres.
- `DELETE /me` : pas encore implémenté (phase 5). Ajouté `ReportRepository::deleteForUserContent(userId)` (signalements sur le compte, ses playlists, artistes, morceaux, commentaires ; les posts restent à `PostCleanup::beforeUserDeletion` ; ceux qu'il a déposés partent par cascade `reporter_id`), testé (`ReportTest::testDeleteForUserContentLeavesNoOrphanReport`), pointeur dans le docblock de `PostCleanup`.
- Tests ajustés au format µs sans affaiblissement : `NotificationTest` (regex `\.\d{6}\+00:00`, cas 1 µs avant → rien marqué), `PhaseThreeWaveThreeSecurityTest` (valeur attendue `….200000+00:00`, test moyen 2 renommé sans `Open`, docblocks « Fixed »), `WaveThreeSecurityTest` (`….100000+00:00`).

## Décisions prises
- Pas de migration. Pas de verrou consultatif sur la suppression de playlist ou d'artiste : un signalement inséré en concurrence après le DELETE serait refusé en amont par le lookup de la cible (404).

## Contrat d'API modifié ?
Non par moi. À relire par le lead : `docs/openapi.yaml` reste incohérent avec sa propre décision : ligne 462 « compared to the millisecond », 2419 et 2439 « Millisecond precision » + exemples `….123Z`. À aligner sur « microseconds, exact » (exemple `2026-11-12T10:19:20.006574+00:00`).

## Reste à faire / points ouverts
- Frontend (W-46) : renvoyer la chaîne `updatedAt` telle quelle, sans `new Date()`.
- Phase 5 `DELETE /me` : appeler `PostCleanup::beforeUserDeletion` puis `ReportRepository::deleteForUserContent` dans la même transaction, avant de supprimer l'utilisateur.

## Commande pour vérifier
`make test c="--no-progress --filter 'PhaseThreeWaveThreeSecurityTest|WaveThreeSecurityTest|NotificationTest|ReportTest|PlaylistTest|ArtistTest|TrackTest|CommentTest|PostTest'" 2>&1 | tail -40` → OK (55 tests) ; PHPStan OK, cs-fixer propre.
