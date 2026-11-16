# W-83 · frontend — Graphiques du dashboard admin (FB-31)

## Fait
- `SeriesCharts.tsx` (nouveau) greffé en bas de `DashboardPage` : bascule 7 j / 30 j (`aria-pressed`), 4 groupes de séries
  (Inscriptions et connexions, Contenus publiés, Signalements et masquages, Alertes par gravité), états chargement / vide / erreur (`AdminError`, retry).
- Modérateur : groupes `null` omis, seul « Signalements et masquages » s'affiche (testé).
- `adminApi.dashboardSeries(range)`, `useAdminSeries`, type `AdminDashboardSeries` / `SeriesRange` (schéma openapi, aucun changement de contrat).
- Tokens `--viz-1..4` (palette catégorielle validée du skill dataviz, clair/sombre) et `--viz-high/medium/low` (palette de statut) dans `index.css`.
- Accessibilité : légende toujours visible, trait en pointillés distincts par série (couleur jamais seule), `<table class="sr-only">` par graphique,
  SVG `aria-hidden`, jours en français (`Intl fr-FR`, dates locales parsées en UTC pour éviter tout décalage), infobulle au survol + valeur du dernier jour.

## Décision
- **Aucune lib de charts** : SVG écrit à la main (0 kB ajouté). `DashboardPage` reste un chunk lazy : 8,54 kB (2,95 kB gzip) ; le bundle des autres utilisateurs est inchangé.

## Tests
- 3 nouveaux Vitest (admin 4 graphiques + tableaux + bascule 30d, modérateur, vide/erreur) ; suite complète 248 verts ; typecheck + oxlint propres.

## Reste / à noter
- Pas de MSW par défaut pour `/admin/dashboard/series` (chaque test le déclare).
- Pas de validation visuelle navigateur faite (assertions DOM uniquement) ; palette non re-validée par script (valeurs du défaut validé).
