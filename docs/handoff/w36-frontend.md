# W-36 — frontend (commentaires et signalements)

## Fait
- Ébauche concurrente relue et consolidée (pas de doublon trouvé ; constantes sorties des fichiers composants pour la règle fast refresh) : `features/comments/CommentsPanel.tsx` + `commentUtils.ts`, `features/reports/ReportForm.tsx`, `ReportButton.tsx`, `reportOptions.ts`, `features/feed/PostSheets.tsx`.
- Commentaires (fil et `/posts/:id`, via `FeedItem`) : liste du plus récent au plus ancien, défilement infini (`InfiniteSentinel`), publication (texte rogné, 1 à 500, compteur), suppression si `canDelete` (retrait optimiste, restauré sur erreur, 404 = déjà parti), `commentCount` optimiste (+1/−1, annulé sur erreur), corps en texte brut, 429 → « Tu commentes trop vite. Réessaie dans N s. », garde e-mail vérifié.
- Signalement : bouton drapeau sur morceau, playlist, artiste, profil (masqué si `isOwner` / `isMe`), menu « Options » du post (masqué sur son post, + « Copier le lien »), menu de chaque commentaire (masqué sur le sien). 8 motifs en français + précisions ≤ 1000 (`null` si vides) ; 201 → « Merci, on regarde », 409 → « Tu as déjà signalé ce contenu », 404 → « Ce contenu n’est plus disponible » (toast + fermeture), 422 `cannot_report_own` et 429 en ligne.
- Feuilles du fil : portail vers `document.body` (sortie du contexte z-10) et `stopPropagation` sur keydown (sauf Échap/Tab) pour que j/k/flèches restent dans la zone de texte.
- Partagés (ajouts seulement) : `api/types.ts` (Comment, CommentPage, Report*), `api/endpoints.ts` (`comments.list/create/remove`, `reports.create`), `components/ui/icons.tsx` (`FlagIcon`). Aucun ajout dans `mocks/handlers.ts` ni `router.tsx` (les tests passent par `server.use`).
- Tests : `comments/comments.test.tsx` (7, dont la feuille dans le fil : vérifié qu’il échoue sans le `stopPropagation`), `reports/reports.test.tsx` (7). Suite front : 214 verts, lint et typecheck propres.

## Décisions prises
- Commentaire en cours de signalement : le panneau reste monté (masqué) pour garder le brouillon et le défilement.
- Commentaire masqué par la modération (visible de son auteur) : mention « masqué par la modération ».
- `timeAgo` propre aux commentaires (W-46 a son `relativeTime` dans notifications) : à fusionner dans `lib/format.ts` plus tard.

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
- Pas de test manuel sur http://localhost:8090 (au test utilisateur : commenter, supprimer, signaler deux fois).
- Pas de handlers MSW de démonstration pour commentaires/signalements (seulement Vitest, comme convenu).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front`
