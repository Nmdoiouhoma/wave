# W-89 frontend — panneau de supervision admin (FB-34)

## Fait
- `frontend/src/features/admin/SupervisionPanel.tsx` (nouveau) : bandeau d'état global, 4 tuiles (pastilles de gravité sur « Activité suspecte », jauge du taux d'échec sur « Connexions »), histogramme 24 h en SVG fait main (axe max / milieu / 0, infobulle au survol, heures Europe/Paris), tableau de repli masqué, légende des 4 niveaux, liste des alertes ouvertes, états zéro et erreur.
- Monté en haut de `DashboardPage.tsx` ; `adminApi.supervision()` et `useAdminSupervision` (`refetchInterval` 30 s, en pause onglet caché) dans `adminApi.ts` / `queries.ts`.
- Jetons `--status-ok|attention|alerte|critique` dans `src/index.css` (clair + les deux blocs sombres) ; icônes `WarningIcon`, `CriticalIcon` dans `components/ui/icons.tsx`.
- 6 tests ajoutés à `admin.test.tsx` (admin, survol, modérateur, critique, zéro, erreur). Le backend W-88 n'existe pas : tout est validé contre des mocks MSW, rien contre l'API réelle.

## Décisions prises
- Le panneau n'est monté (et l'endpoint appelé) que si `alerts` de `/admin/dashboard` est non nul : un modérateur ne déclenche aucun appel de supervision. La forme nulle (`global`/`hourly` nuls, `signals` vide) masque aussi le panneau si elle arrive quand même.
- Alertes ouvertes : réutilisation de `GET /admin/alerts?status=open` (W-75), 5 premières + lien vers `/admin/alerts`. Aucun endpoint inventé.
- Pastilles de gravité : compteurs `openHigh/openMedium/openLow` de `/admin/dashboard` (cache 1 min), pas du contrat de supervision.
- Couleur d'état = fond d'un carré avec icône à encre sombre fixe `#16161a` + libellé en couleur de texte ; mêmes valeurs en clair et en sombre (non contrôlées à l'œil).
- Libellés : Normal / Attention / Alerte / Critique. Heure écrite à la main (`14 h`) car Intl n'ajoute pas toujours l'unité.
- Histogramme tout à zéro : message à la place du graphique, tuiles et légende conservées.

## Contrat d'API modifié ? (oui/non + détail)
Non. Manques relevés, sans toucher `docs/openapi.yaml` :
- `SupervisionSignal` ne porte pas le détail par gravité (pastilles du ticket) : pris sur `/admin/dashboard`, d'où un écart possible jusqu'à 1 min avec `value` de `suspicious`.
- `SupervisionSignal.value` de `logins` vaut 0 quand il y a trop peu de tentatives : indiscernable d'un vrai 0 % sauf par `detail`.
- Aucun champ ne dit sur quels seuils la jauge est graduée : repères 20 / 50 / 80 % écrits en dur côté front.

## Reste à faire / points ouverts
- Vérifier contre le vrai endpoint W-88 (ordre des `signals`, 24 heures, `detail` en français) et à l'œil en clair/sombre.
- Pas de mock MSW par défaut pour `/admin/*` (comme l'existant) : en mode mocks navigateur le panneau n'apparaît pas.

## Commande pour vérifier
`bash /Users/nakib/wave/.claude/skills/wave-verif/verif.sh front` → VERDICT OK, 273 tests Vitest.
