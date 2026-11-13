# ux-1113 frontend (FB-25, FB-26)

## Fait
- FB-25 : `features/auth/LogoutButton.tsx` (useLogout + redirection /login, comme SettingsPage, dont le bouton reste).
  Desktop : bloc compte en bas du rail (lien Paramètres avec avatar + bouton Se déconnecter) = 1 clic.
  Mobile : bouton sur son propre profil (onglet Profil), masqué en md+ (`md:hidden`).
- FB-26 : à partir de 1024 px (`WIDE_QUERY`), `FeedViewer` passe en flex : colonne 9:16 centrée dans l'espace libre
  + `PostSidePanel.tsx` (w-96) avec pochette/titre/artiste (lien piste) et commentaires du post actif.
  Valable pour `/`, `/following` et `/posts/:id` (même FeedViewer). Téléphone et 768-1023 px inchangés (sheet).
- `CommentsBody` extrait de `CommentsSheet` (PostSheets.tsx) et réutilisé par le panneau (liste, composer, signalement).
- Tests : navigation.test.tsx (déconnexion rail + profil mobile), feed.test.tsx (panneau lg / absent sur téléphone).

## Décisions prises
- Panneau à lg (1024) et non md : le rail (240 px) + colonne + 384 px ne tient pas en 768.
- Bouton Commentaires du post masqué à lg+ (`lg:hidden`) : le panneau le remplace ; le compteur optimiste du
  post n'est donc pas synchronisé avec le panneau (le compteur du bouton est invisible à ce moment-là).
- Le panneau stoppe la propagation des touches (hors Échap/Tab) : taper un commentaire ne déclenche pas j/k/flèches.
  Molette/clavier FB-24 inchangés (listeners sur le scroller/document).
- Aucune couleur/typo nouvelle (tokens existants), structure seule.

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Pas de contrôle navigateur (média à couper) : à voir au test manuel, surtout hauteur du panneau et header de FeedPage
  (positionné absolu sur toute la largeur, au-dessus du panneau).
- Flèches haut/bas du clavier ne naviguent pas quand le focus est dans le panneau (volontaire).
- FB-27 (refonte) pourra restyler le panneau.

## Commande pour vérifier
`cd frontend && npm run typecheck && npm run lint && npx vitest run` (223 tests OK ; `wave-verif` introuvable dans le PATH, équivalent lancé).
