# W-43 frontend — Découvrir (FB-32)

## Fait
- `/discover` champ vide : `features/discover/` (DiscoverHomeView, TrackCards, Suggestions, compose.ts) ; suit la maquette validée (mise en avant, genres, rangées Pour toi / Nouveautés / En tendance, comptes + hashtags, Artistes célèbres). Un seul appel `GET /discover` (`api/queries/discover.ts`, stale 5 min, clé `keys.discover()`).
- Règle de remplissage dans `compose.ts` : maigre si < 12 cartes dans les rangées (`THIN_ROWS_THRESHOLD`) → comptes 6 au lieu de 5, proportions 1.2/1, Artistes célèbres limité à 4 (`FamousArtistsSection limit`). Rangée vide masquée ; mise en avant = 1er Pour toi, sinon nouveautés, sinon tendances, retirée des rangées.
- Lecture via le lecteur global (`playerStore.playTracks`, file = la rangée) ; clic = lecture, second clic = pause ; anneau + pastille « En cours » + barres ; « Lecture impossible, réessaie. » si statut erreur.
- Colonnes latérales des fils : `FeedViewer` accepte `leftColumn`/`rightColumn` ; Pour toi ≥ 1280 px (colonne gauche 232 px, sans compteurs, panneau FB-26 conservé), Abonnements ≥ 1024 px (colonne droite remplace le panneau). Abonnements vide ET `me.followingCount === 0` : suggestions inline à la place de l'état vide.
- États : chargement, erreur + Réessayer, tout vide (« Rien à découvrir… »).
- Mocks : `mocks/discoverHandlers.ts` ajouté aux handlers par défaut (`npm run dev:mocks`).
- Tests : `features/discover/discover.test.tsx` (ordre, remplissage, lecture, Suivre, états, colonnes) + 1 test dans `feed.test.tsx`.

## Décisions prises
- Réutilisés tels quels : `FollowButton` (libellé « Abonné·e », échec en toast, pas en ligne comme la maquette), `UserList` (nouvelle prop `compact`), `LikeButton`, `Cover`, `relativeTime`.
- Liens hashtag → `/discover?q=%23nom` (page hashtag/recherche = W-41, pas encore là). `hashtagHref.ts`.
- Surlignage des 3 genres préférés seulement si `forYouTracks` non vide.
- Nouvelle requête média `XL_QUERY` (1280 px).

## Contrat d'API modifié ? non
Aucun changement ; types alias ajoutés (`DiscoverHome`, `DiscoverForYouTrack`, `Hashtag`).

## Reste à faire / points ouverts
- Brancher `?q=#tag` quand W-41 existe ; version « Suivi » de la maquette vs « Abonné·e » à trancher.
- Pas de contrôle visuel navigateur fait (tests DOM seulement).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front`
