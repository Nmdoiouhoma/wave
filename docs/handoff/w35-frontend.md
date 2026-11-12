# w35 — frontend (composeur de post `/posts/new`)

## Fait
- `features/posts/PostComposerPage.tsx` (+ `TrackPicker`, `ClipSelector`, `VisualField`, `CaptionField`, `postRules.ts`) ; remplace le composeur factice de `PostStubPages.tsx`.
- Garde e-mail : `useVerifiedGate` (message, pas de formulaire). `?trackId=` : utilisé seulement si `canClip` et prêt, sinon message + « Choisir un de mes morceaux » ; sans paramètre : liste de mes morceaux prêts, visibles, ≥ 15 s (`/users/{me}/tracks`, défilement infini).
- Début + durée 15–60 s (curseurs, bornés par la durée du morceau), aperçu du segment par la stream-url du morceau (le lecteur global est mis en pause, arrêt à la fin du segment).
- Visuel : type jpg/png/webp/mp4, image ≤ 5 Mo, vidéo ≤ 15 Mo et ≤ 60 s lus dans les métadonnées, avant envoi. Légende ≤ 300 (points de code) avec compteur et surlignage des `#hashtags` (texte React, jamais de HTML).
- `PostAudienceWarning` (W-48) monté au-dessus de « Publier ». Envoi multipart avec progression, puis sondage 2 s de `GET /posts/{id}` : `ready` → `/posts/:id`, `failed` → motif en français + « Réessayer » (formulaire conservé), 2 min → « Toujours en cours » + lien vers le profil.
- `errorMessages.ts` : `postErrorMessage` (413/415 parlent du visuel, 404, 409), `postFailureMessage`, codes `clips_not_allowed`, `clip_out_of_range`.
- Tests : `PostComposerPage.test.tsx` (21), `postRules.test.ts` (3).

## Décisions prises
- Ticket lu strictement : un morceau d'un autre se choisit seulement via `?trackId=` (pas de recherche dans le composeur).
- Morceaux < 15 s absents de la liste (aucun extrait possible).

## Contrat d'API modifié ? non

## Fichiers partagés touchés (à fusionner avec W-34)
- `api/types.ts` : `Post`, `PostVisual` ; `api/endpoints.ts` : `api.posts.{create,get}` (W-34 : compléter ce bloc, pas le dupliquer) ; `api/queries/tracks.ts` : option `enabled` de `useTrack`.
- `routes/router.tsx` : une ligne (`posts/new` → `PostComposerPage`). `PostStubPages.tsx` : composeur factice retiré, viewer intact.
- Sondage avec clé `['post', id]` inline (pas de `keys.post`).

## Reste à faire / points ouverts
- Pas de handlers MSW `/posts` globaux : les tests définissent les leurs (`server.use`).
- À tester à la main : aperçu audio sur iOS, vidéo MP4 réelle, bouton « + » → `/posts/new`.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` (VERDICT OK, 169 tests)
