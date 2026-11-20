# W-43b frontend — Découvrir : recherche à onglets + page hashtag (FB-32)

## Fait
- `/discover` champ non vide (≥ 2 car., debounce 300 ms, `?q=`) : `features/search/SearchResults.tsx` + `searchTabs.ts`. Onglets Tout / Personnes / Morceaux / Artistes / Playlists / Hashtags, onglet dans l'URL (`?tab=`) ; une requête `#tag` ouvre Hashtags par défaut.
- « Tout » = `GET /search` sans `type` (sections ≤ 5, « Voir tout » ouvre l'onglet typé, sections vides masquées) ; onglets typés = `type=` paginé (curseur, `InfiniteSentinel` / « Charger plus » pour les morceaux).
- Gardés : recherche Personnes W-28 (liste « Résultats pour … », annulation de requête, 429), bloc catalogue (`CatalogSearchBlock`) sous les résultats, vide < 2 car. et accueil champ vide.
- Page hashtag `/hashtags/:name` (`HashtagPage.tsx`, onglet actif Découvrir) : `GET /hashtags/{name}` puis `/posts` (grille, curseur) ; états chargement / vide / erreur + Réessayer / 404. Carte = visuel du post, sinon pochette, lien `/posts/:id`.
- `hashtagHref.ts` → `/hashtags/<nom>`.
- API : `api.search.overview/section`, `api.hashtags.get/posts`, hooks `useSearchOverview`, `useSectionSearch` (social.ts), `queries/hashtags.ts`, clés `searchOverview/searchSection/hashtag/hashtagPosts`.
- Mocks : `/search` accepte tous les types + sans type (5 max) ; `/hashtags/:name` (404 si inconnu) et `/posts` (page vide, la db mock n'a pas de posts).
- Tests : `features/search/search.test.tsx` (vue d'ensemble, Voir tout, onglets, `#`, page hashtag 4 états) ; `social.test`, `discover.test`, `navigation.test` ajustés.

## Décisions prises
- Libellé du champ « Rechercher sur Wave » (placeholder élargi) ; l'indice « personnes uniquement » remplacé.
- Liste Personnes de la vue d'ensemble : « Personnes pour … » ; vide global : « Rien ne correspond à … ».
- `q` envoyé tel quel (avec `#`) à `/search` ; le nom du hashtag de la page est mis en minuscules, `#` retiré.

## Contrat d'API modifié ? non
Aucun ; alias de types ajoutés (`SearchType`, `ArtistPage`, `HashtagPage`).

## Reste à faire / points ouverts
- À confirmer côté backend : `q` commençant par `#` sur `type=hashtags` (le front ne retire pas le `#`).
- Pas de contrôle visuel navigateur (tests DOM seulement).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` (VERDICT OK)
