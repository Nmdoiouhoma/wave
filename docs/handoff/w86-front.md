# W-86 · frontend — Catalogue UI (ADR 014, FB-06)

## Fait
- Tout derrière `me.features.catalog` (flag lu, aucune sonde de route) : `/discover` affiche « Artistes célèbres » (`/catalog/charts/artists`) ; avec `?q=` ≥ 2 car., bloc **séparé** « Artistes du catalogue » sous les résultats Wave (jamais mélangés).
- Page `/discover/artists/:externalId` (id validé `^dz:\d{1,15}$`, flag off ou 404 → NotFound) : fiche artiste + top tracks, bouton extrait, lien « Écouter sur Deezer » (`rel="noopener noreferrer"`).
- Attribution « Données et extraits : Deezer » sous chaque bloc ; `available:false` → section omise ; 503 → message de réessai + bouton ; 429 → alerte.
- Lecteur léger dédié `features/catalog/previewPlayer.ts` : `<audio>` sans `crossOrigin`, une seule lecture à la fois, aucune écoute/grant, aucune action like/playlist/post ; sur erreur média, refetch de l'artiste **une fois** puis retry avec l'URL fraîche, sinon « Extrait indisponible ». Arrêt au démontage de la page.
- Fichiers : `src/features/catalog/*`, `api/queries/catalog.ts`, `api/endpoints.ts` (`catalog.*`), `api/types.ts`, `keys.ts`, route dans `router.tsx`, `SearchPage.tsx` (greffe), `mocks/catalogHandlers.ts` (+ `mockConfig.catalog`), message FR `catalog_unavailable`.

## Décisions
- Lecteur hors du player store : seul contact = `playerStore.getState().pause()` quand un extrait démarre (Wave se met en pause, état intact). Store non modifié.
- Pas de changement de contrat.

## Vérifs
- typecheck : seules erreurs = `admin.test.tsx` l.79/169 (`problem(500,'n/a'|'boom')`, antérieures, hors lot) ; lint : 1 warning préexistant `admin/SeriesCharts.tsx:148` ; Vitest 254/254 (6 nouveaux). `wave-verif` introuvable dans le PATH : équivalent lancé à la main.
- Tokens existants (surface-2, ring-line, accent) → clair/sombre hérités ; pas de nouveau token.

## Reste / à vérifier
- Test visuel réel avec backend W-84 (`CATALOG_PROVIDER=deezer`) : lecture réelle des extraits Deezer.
