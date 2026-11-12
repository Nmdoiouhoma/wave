# W-46 — cloche de notifications (frontend)

## Fait
- Ébauche à deux mains fusionnée en une version : `features/notifications/` = `notificationsApi.ts` (hors `endpoints.ts`, pour ne pas croiser W-36), `queries.ts` (compteur, liste infinie, `useMarkRead`, `useBellLabel`), `notificationText.ts` (phrases, liens, date relative, `99+`, dédoublonnage, `readUpTo`), `NotificationBell.tsx` (icône, badge, cloche), `NotificationsPage.tsx` ; `mocks/notificationHandlers.ts` (réponses vides par défaut).
- Cloche : mobile en haut à droite du bandeau Pour toi/Abonnements (`FeedPage`) et de la barre du haut sur l'onglet Profil ; bureau : entrée « Notifications » du rail, placée hors de la `nav` principale (ce n'est pas un onglet, aucun onglet actif sur `/notifications`).
- Un seul scrutateur (`AppLayout`) : 60 s tant que la page est visible (pause TanStack quand cachée), relance au retour de focus ; les cloches lisent le cache.
- Page : défilement infini, phrases en français, avatars, vignette de la cible, date relative, non lues surlignées, libellés en texte brut, états vide et erreur ; tap → `/u/:username` (follow), `/t/:id`, `/p/:id`, `/posts/:id`.
- `read` envoyé avec la chaîne `updatedAt` du premier élément affiché, telle quelle, seulement après le chargement de cette visite (pas avec un cache d'une visite précédente) ; badge mis à 0 tout de suite, compteur serveur repris ensuite.
- Tests `notifications.test.tsx` (12) : phrases et liens, `99+`, date µs, `upTo` = chaîne exacte (corps de la requête comparé à `…20.006574+00:00`), badge à 0 pendant la requête, HTML du libellé affiché en texte, vide, erreur, scrutation (vérifié : le test échoue si on active la scrutation en arrière-plan).

## Décisions prises
- API des notifications dans `features/notifications/notificationsApi.ts` plutôt que dans `api/endpoints.ts` (fichier partagé avec W-36) ; à déplacer si le lead préfère, sans autre changement.
- Date relative : fractions au-delà de la milliseconde coupées avant `Date.parse`, pour l'affichage seulement.

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
- Pas de marquage lu si la liste est vide alors que le compteur est > 0 (cas d'éléments omis entre deux appels) : le compteur se recale au prochain sondage.
- Test manuel : position de la cloche sur mobile (encoche) et dans le rail.

## Commande pour vérifier
`cd frontend && npm run typecheck && npm run lint && npx vitest run src/features/notifications src/routes --reporter=dot` → OK ; suite complète : 27 fichiers, 214 tests verts.
