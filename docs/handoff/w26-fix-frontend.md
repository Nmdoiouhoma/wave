# w26-fix — frontend

## Fait
- `npm run gen:api` : `ProblemCode` contient `playlist_limit_reached` ; `PlaylistItem.track` nullable (owner).
- `lib/errorMessages.ts` : « Tu as atteint la limite de 200 playlists. » (affiché dans l'Alert de `PlaylistForm`,
  donc création depuis la bibliothèque et depuis « Créer et ajouter »).
- 429 : déjà couvert par `errorMessage` (« Trop de tentatives. Réessaie dans N s. ») sur création et ajout d'item.
- `PlaylistPage` : `track: null` (propriétaire) affiché « Titre indisponible » (était « Morceau indisponible ») ;
  items omis (non-propriétaire) : rien à faire, la liste est rendue telle que reçue.
- Tests (`playlists.test.tsx`, bloc « W-26 fixes ») : 409 limite, 429 création, track null propriétaire, liste vide non-propriétaire.

## Vérifié
typecheck, lint, Vitest 79/79 verts.

## Décisions / contrat
Aucun changement de contrat. Libellé renommé selon la mission.

- Re-gen `gen:api` après changement openapi (descriptions F14 unlike) : typecheck + Vitest verts.

## Reste
Rien côté front. Pas de test dédié pour le 429 d'ajout d'item (même chemin `errorMessage`).
