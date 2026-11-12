# W-34 — frontend (fil vertical « Pour toi | Abonnements »)

## Fait
- `features/feed/` : `FeedPage` (`/`, `/following`, onglets « Fils »), `PostPage` (`/posts/:id`, 404 → « Ce post n’est plus disponible »), `FeedViewer` (snap plein écran, clavier j/k/flèches, boutons précédent/suivant en desktop), `FeedItem` (visuel image / vidéo muette en boucle avec poster / pochette, légende en texte brut, auteur → `/u/…`, morceau → `/t/…`, « Utiliser ce son » si `track.canClip`, like optimiste + double tap avec retour arrière), `useFeedSession` (dédoublonnage par id, page courte avec curseur → page suivante tout de suite, `invalid_cursor` → reprise sans curseur, fin « Tu as tout vu » + « Recommencer » = nouvelle session, état vide → Découvrir).
- `feedPlayback.ts` : contrôleur hors React à 2 éléments audio (actif + préchargé du suivant), met le lecteur global en pause au démarrage d’un extrait (sans reprise), pause à l’onglet masqué, URL renouvelée par `/posts/{id}/stream-url` si expirée (< 5 s) ou sur erreur média (1 essai), vues ≥ 1 s groupées vers `/post-views` toutes les 10 s, à la sortie du fil et au `pagehide` (`keepalive`), écoute `source=feed` + `postId` à la sortie d’un extrait.
- Fichiers partagés (ajouts seulement) : `api/types.ts` (`PostPage`, `PostViewsRequest`, `PostViewReport`, `FeedSource`), `api/endpoints.ts` (`posts.streamUrl/like/unlike/reportViews`, bloc `feed.page`), `mocks/handlers.ts` (+ `feedHandlers` : pages vides par défaut).
- Routeur (modif minimale) : index → `FeedPage for-you`, `following` → `FeedPage following`, `posts/:id` → `PostPage` ; `posts/new` intact. Supprimés : `FeedStubPage.tsx`, `posts/PostStubPages.tsx` (ne contenait plus que le viewer bouchon).
- `routes/navigation.test.tsx` : texte attendu « Aucun extrait pour l’instant » au lieu de l’ancien bouchon.
- Tests : `feedPlayback.test.ts` (8), `feed.test.tsx` (10). Suite front : 187 verts.

## Décisions prises
- Onglet masqué puis revenu : l’extrait reprend seulement s’il avait été mis en pause par le masquage (pas de reprise du lecteur global).
- `listenedMs` d’une écoute fil plafonné à `clipDurationMs` (DATA_MODEL §11 : référence + 2 s) ; `watchedMs` plafonné à min(durée de l’extrait, 600 000).
- Vues de ses propres posts non envoyées (le serveur les ignore de toute façon).
- Double tap = like uniquement (jamais d’annulation) ; tap simple différé de 250 ms pour le distinguer.
- Commentaires et signalement : boutons présents, toast « arrive bientôt » jusqu’à W-36.
- `fetchNextPage({ cancelRefetch: false })` : la page courte et l’approche de la fin ne doublent pas la requête.

## Contrat d'API modifié ?
Non.

## Reste à faire / points ouverts
- Autoplay bloqué par le navigateur au chargement à froid : badge « Touche pour écouter », un tap lance.
- Pas de test manuel fait sur http://localhost:8090 (à faire au test utilisateur : 2 onglets, double tap, « Recommencer »).
- Hauteur de la bannière de vérification d’e-mail non déduite du plein écran ; pas de virtualisation (seuls les médias à ±1 sont montés).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front`
