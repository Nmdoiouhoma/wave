# fb-1112 frontend

## Fait
- FB-19 VolumeControl.tsx (min-w, shrink-0) + MiniPlayer.tsx ; stores/player.ts.
- FB-20 ProfilePage.tsx : en-tête qui passe à la ligne, colonne texte min-w-56.
- FB-21 SearchPage.tsx : état local du champ, URL synchronisée après debounce (test frappe rapide).
- FB-22 « résultat(s) affiché(s) » accordé ; les autres compteurs utilisaient déjà `> 1`.
- FB-23 api/queries/social.ts : onSettled invalide `keys.user(username)` (préfixe : titres, artistes, playlists, abonnés).
- FEEDBACK.md : FB-19 à FB-23.

## Décisions prises
- Volume 0 persisté : `playTracks` repart à 0.9 non muet. Muet avec volume > 0 est laissé (l'icône l'indique).
- Volume du mini-lecteur toujours masqué sous lg (inchangé) ; atteignable dans le lecteur plein.

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Le bug 0 × 0 n'a pas été reproduit en navigateur (correctif par lecture du CSS) : à revérifier à ~780 px.

## Commande pour vérifier
cd frontend && npm test -- --reporter=dot && npm run lint && npx tsc --noEmit
