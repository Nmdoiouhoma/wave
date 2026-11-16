# W-81 · frontend — Repost UI (ADR 015)

## Fait
- Bouton repost + compteur dans `FeedItem` (donc fil ET détail `/posts/:id`, même composant) : `usePostRepost`, copie de la mécanique optimiste de `usePostLike` (surcharge locale, annulation sur erreur, état serveur à la réponse). Caché quand `!canRepost && !reposté` ; l'annulation reste offerte si `repostedByMe`.
- Messages français (`repostErrorMessage`, `lib/errorMessages.ts`) : 403 `email_not_verified`, 404, 422 `cannot_repost_own` / `repost_not_allowed`, 429 `rate_limited` avec délai `Retry-After`.
- En-tête « Reposté par X » (lien vers le profil) quand `repostedBy` est renseigné.
- Profil : onglet « Reposts » (`useUserReposts`, `GET /users/{username}/reposts`, pagination par curseur), chaque ligne ouvre `/posts/:id`.
- Notification « a reposté ton post » : déjà posée par `p4-contract`, ajout du cas de test.
- Mocks : `makePost` et la fixture de `PostComposerPage.test` portent les 5 nouveaux champs de `Post` ; handlers par défaut `users/:username/reposts` (page vide) et `PUT/DELETE posts/:id/repost` (404, la base mock n'a pas de posts).
- Tests : `features/feed/repost.test.tsx` (12 tests) + 1 cas dans `notifications.test.tsx`. Total 267.

## Décisions prises
- État du repost local au composant (comme le like de post), pas dans `stores/social` : un même post affiché à deux endroits ne se synchronise qu'au rechargement.
- Icône active en vert fixe (`text-emerald-400`) : l'accent du thème clair est illisible sur le fond noir du fil.
- Onglet « Reposts » en liste simple (visuel, légende ou titre, auteur, date du repost) : il n'existe pas de grille de posts à réutiliser.
- **`src/api/schema.d.ts` régénéré (`npm run gen:api`)** : il était périmé avant ce lot, `check:api` échouait. Écart purement additif et hors repost : `/admin/dashboard/supervision` et schémas `Supervision*` (FB-34) présents dans `docs/openapi.yaml` mais pas dans le fichier généré.

## Contrat d'API modifié ? non
Rien de manquant ni d'incohérent pour le repost. `docs/openapi.yaml` non touché.

## Reste à faire / points ouverts
- Le compteur de l'en-tête du profil n'affiche pas de nombre de reposts (absent de `UserProfile`, non demandé).
- Rendu non vérifié à l'œil dans le navigateur (tests DOM seulement).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` · ciblé : `cd frontend && npx vitest run src/features/feed/repost.test.tsx --reporter=dot`
