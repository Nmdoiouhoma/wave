# Passation phase 2 — frontend (W-24, W-25, W-28)

## Fait
- W-24 Bibliothèque : `features/playlists/` (LibraryPage avec onglets `?onglet=` pour Mes playlists, Titres likés et Playlists likées ; PlaylistPage chargée en lazy ; PlaylistForm ; PlaylistGrid ; TrackSheets), `api/queries/playlists.ts`. Création, modification et suppression ; publique ou privée ; « Ajouter à une playlist » depuis TrackList, FullPlayer et TrackPage ; lecture de toute la playlist dans l'ordre ; réordonnancement avec dnd-kit (souris, tactile 180 ms, clavier, annonces en français), PUT de l'ordre complet, optimiste avec annulation sur 409.
- W-25 Profil social : `features/social/` (LikeButton, FollowButton, UserList, FollowListPage), `api/queries/social.ts`, `stores/social.ts` (overrides optimistes partagés par profil, listes, recherche et lecteur ; vidé à la déconnexion). Abonnés et abonnements en défilement infini.
- W-28 Recherche : `features/search/SearchPage.tsx` (`?q=`, debounce de 300 ms, 2 caractères minimum, requête précédente annulée, états trop court, chargement, vide, erreur et 429). Ajout de « Rechercher » dans la barre du bas (5 entrées) et dans la barre latérale.
- Éléments communs : Sheet (focus piégé), Toaster, InfiniteSentinel, `stores/{ui,toast}.ts`, `lib/useDebounced.ts`. Mocks : `mocks/{socialHandlers,socialFixtures,handlerUtils}.ts`, dont 40 fans et 3 playlists.
- Tests : `playlists.test.tsx` (9) et `social.test.tsx` (7). Au total, 75 tests dans 14 fichiers sont verts. Build : bundle principal de 490,7 kB et PlaylistPage de 52 kB. Le dist ne contient aucun mock.
- Test manuel dans Chrome headless à 360 px avec les mocks : le glisser-déposer est conservé après rechargement, la recherche « cam » donne 2 résultats, aucune erreur dans la page.

## Décisions prises
- La liste d'une playlist charge toutes ses pages (limit 100, plafond de 500 éléments), parce que le PUT du réordonnancement exige l'ordre complet.
- Sur `playlist_order_mismatch`, l'ordre est annulé, la liste rechargée et un toast s'affiche.
- La feuille « Ajouter à une playlist » liste seulement les playlists que je possède, et propose « Nouvelle playlist » pour créer et ajouter en une fois.
- Tout le texte de l'interface est au tutoiement ; un grep sur vous/votre/vos ne trouve rien.

## Contrat d'API modifié ? (oui/non + détail)
- Non. Types régénérés depuis 26d815b ; `check:api` passe (code 0).

## Reste à faire / points ouverts
- La feuille d'ajout ne montre que les 20 premières playlists, sans pagination.
- Le `UserSummary` du propriétaire d'une playlist n'a pas d'information d'abonnement, donc pas de bouton Suivre à cet endroit.
- Le classement de la recherche dans les mocks est approximatif (exact, puis préfixe, puis sous-chaîne, puis abonnés). Le vrai classement reste à vérifier contre le backend.
- À tester sur un vrai mobile : glisser-déposer tactile et barre du bas à 5 entrées.

## Commande pour vérifier
`cd frontend && npm run typecheck && npm run lint && npm test -- --reporter=dot && npm run check:api && npm run build`
