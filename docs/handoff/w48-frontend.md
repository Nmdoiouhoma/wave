# w48 — frontend (FB-18, audiences des morceaux)

## Fait
- Types régénérés (`npm run gen:api`) ; alias `CloseFriendState/Item/Page` ; `api.me.closeFriends|addCloseFriend|removeCloseFriend` ; `not_a_follower` → « Cette personne ne te suit plus. ».
- `features/tracks/AudienceField.tsx` (radiogroup 4 choix, libellés accessibles, aide « Toute personne qui te suit » sous Abonnés) monté à l'import (`MultiUploader`) et dans l'édition (`TrackPage`, remplace le select). « Amis proches » : nombre de membres (1 page de 50, « 50+ ») + lien « Gérer mes amis proches » (nouvel onglet, pour ne pas perdre le formulaire). Note « Tes extraits de ce morceau suivront cette audience. » dès que l'audience diffère de celle enregistrée.
- `/settings/close-friends` (`features/profile/CloseFriendsPage.tsx`), lien depuis Paramètres : abonnés (`scope=followers`), interrupteur par ligne, défilement infini, bascule optimiste + annulation, 422 → toast + ligne retirée, état vide « Personne ne te suit encore ».
- Badge d'audience (`TrackStatusBadge`, donc profil, artiste, page morceau) : propriétaire : Abonnés / Amis proches / Moi seul (icônes personnes / étoile / cadenas) ; autre utilisateur avec `visibility: null` : « Partagé avec toi » ; rien pour `public`.
- `features/posts/PostAudienceWarning.tsx` + tests, non monté (W-35 le place au-dessus de « Publier »).
- MSW : prédicat d'audience (`inAudience`), `visibility` réelle au propriétaire sinon `public`/`null`, 4 valeurs en création/PATCH, `/me/close-friends*`, désabonnement = sortie de la liste ; fixtures : Nour amie proche de Léa, morceau Kenji « Pour mes abonnés ».
- Tests : `tracks/audience.test.tsx`, `profile/CloseFriendsPage.test.tsx`, `posts/PostAudienceWarning.test.tsx` (+25) ; `AllowClipsField.test.tsx` adapté.

## Décisions prises
- Libellés de la décision utilisateur (Public / Abonnés / Amis proches / Moi seul), pas ceux du ticket (« Mes abonnés », aide « …s'abonne à toi… »).
- `null` chez un autre → « Partagé avec toi » (ticket W-48, confirmé par le lead) ; libellés et « 50+ » validés par le lead.
- Le badge « Privé » devient « Moi seul ».
- La note sur les extraits s'affiche à tout changement d'audience : le contrat n'indique pas si le morceau a des posts.

## Contrat d'API modifié ? non (consommé tel que livré par vis-1110)

## Reste à faire / points ouverts
- Contrat : pas de nombre de membres dans `/me/close-friends` → affichage plafonné « 50+ ». Un champ `closeFriendCount` sur `Me` serait plus juste.
- W-35 : monter `PostAudienceWarning` avec le morceau choisi.
- Test manuel contre le vrai backend W-47 (422 `not_a_follower` en course réelle).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` (146 tests Vitest, OK)
