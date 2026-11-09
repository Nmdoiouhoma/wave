## Fait
- `src/api/schema.d.ts` régénéré (contrat 73c615b : `type=tracks`, notifications) ; `npm run check:api` passe.
- FB-14 : `MultiUploader.tsx`, le menu groupé s'appelle « Appliquer un genre à tous » et n'apparaît qu'à partir de 2 fichiers prêts. Tests mis à jour (2 tests à 1 fichier passent par « Genre de <fichier> », 1 assertion « menu absent avec 1 fichier »).
- W-29b : bouton « Ajouter des morceaux » (propriétaire seulement) sur `PlaylistPage` → `features/playlists/AddTracksSheet.tsx`. Sous 2 caractères : sections « Tes morceaux » (`/users/{me}/tracks`) et « Tes titres likés » (`/me/likes/tracks`) ; à partir de 2 : `/search?type=tracks` (300 ms, annulation par TanStack, défilement infini). Chaque ligne : « Ajouter » ou « Ajouté » (déjà dans la playlist, ajout réussi ou 409 doublon). `playlist_full` et 429 en français ; compteurs rafraîchis par la mutation existante `useAddToPlaylist`.
- API : `api.search.tracks`, `keys.searchTracks`, `useTrackSearch` (social.ts). Messages d'erreur d'ajout déplacés dans `features/playlists/addErrors.ts` (partagés avec `TrackSheets`).
- MSW : `/search` accepte `type=tracks` avec les règles W-29a (prêt, visible, public ou à soi, propriétaire non suspendu ; à soi puis likés en premier).
- Tests : `features/playlists/addTracksSheet.test.tsx` (6 tests). Total front : 85 tests, typecheck et lint propres.

## Décisions prises
- FB-14 : le seuil compte les fichiers **prêts** (non envoyés), car le menu ne s'applique qu'à eux.
- Les sections « Tes morceaux » et « Tes titres likés » filtrent côté client `status=ready` et `moderation=visible` (la route du propriétaire renvoie aussi ses morceaux en traitement, en échec ou masqués).
- La recherche n'apparaît pas dans les sections ; aucune fusion côté client (classement backend).
- `onClose` de la feuille stabilisé par `useCallback` : `Sheet` relance son effet de focus à chaque changement de `onClose`.

## Contrat d'API modifié ? non (types régénérés seulement)

## Reste à faire / points ouverts
- À tester contre le vrai backend W-29a une fois livré (seul le mock a été utilisé).
- `src/routes/router.tsx` n'est pas formaté selon Prettier (antérieur à ce lot, non touché).
- Le texte d'aide de `SearchPage` (« Personnes uniquement pour l'instant ») reste vrai : la page de recherche ne cherche pas encore de morceaux.

## Commande pour vérifier
`cd frontend && npm run check:api && npm run typecheck && npm run lint && npm test -- --reporter=dot 2>&1 | tail -6`
