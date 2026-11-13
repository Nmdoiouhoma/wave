# design-1113 — frontend (FB-27 "Papier", clair + sombre)

## Fait
- Tokens: `frontend/src/index.css` — variables CSS `--bg --surface --surface-2 --line --text --muted --accent --accent-contrast --like --danger --success --warning`, jeu clair (`:root`), jeu sombre (`prefers-color-scheme` sauf `data-theme=light`, et `data-theme=dark`). Les noms Tailwind existants (`bg`, `surface`, `line`, `fg`, `muted`, `accent`…) pointent sur ces variables ; `faint` et `accent-strong` sont des alias de `muted` / `accent`. Nouveaux : `accent-contrast`, `like`. Valeurs tirées des maquettes B (clair) / D (sombre).
- Police: DM Sans via npm `@fontsource-variable/dm-sans` (auto-hébergée, CSP ok). Nouvelle dépendance.
- Thème: `stores/theme.ts` (Clair/Sombre/Système, localStorage en try/catch, test `theme.test.ts`), `components/ui/ThemeToggle.tsx` dans le bloc compte du rail desktop et dans Paramètres (section « Apparence »). Pas de flash: `public/theme-init.js` chargé en `<head>` (fichier externe car la CSP interdit l'inline), `theme-color` clair/sombre.
- Dégradés `bg-wave` / `text-wave` supprimés (utilitaires retirés) : boutons primaires, badges, logo texte « wave. », onglet Créer, auth, 404, barres de progression, notifications.
- Boutons: pilule, primaire accent, secondaire/danger en contour. Rail: pilule active `bg-fg text-bg`, bloc compte en carte bordée. Auth: carte 28 px bordée, sans blobs. Avatars: pastel plat + texte sombre. Cœur liké en `like`.
- Fil: stage `bg-bg`, colonne centrale desktop en carte arrondie bordée, boutons précédent/suivant et états vides thémés ; médias et surcouches vidéo restent blanc/noir (lisibilité sur image, voulu).

## Décisions prises
- Tokens sous les noms Tailwind existants (migration sans toucher chaque écran) ; thème via fichier externe `theme-init.js` (CSP) ; médias du fil gardés blanc/noir.

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Migration par les tokens : tous les écrans suivent automatiquement; les rayons/ombres fins de chaque carte (`ring-1 ring-line` conservé, équivalent visuel d'une bordure 1 px) n'ont pas été repris écran par écran.
- Aucun contrôle navigateur fait (pas de capture) : contrastes calculés sur les paires de tokens (texte ≥ 4,5:1), à confirmer à l'œil, surtout profil, bibliothèque et sheets en sombre.
- Pas de « menu compte » dédié : bascule dans le rail (desktop) et Paramètres (mobile).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` : VERDICT OK (225 tests).
