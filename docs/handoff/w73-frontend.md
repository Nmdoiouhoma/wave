# W-73 — frontend (back-office : shell, tableau de bord, utilisateurs)

## Fait
- Coquille `/admin` (`features/admin/AdminLayout.tsx`) : garde sur `me.roles` (non-staff → `/`), menu par rôle (`adminNav.ts`, `adminOnly`), menu à gauche sur PC, onglets défilants en haut sur téléphone. Route lazy (`adminRoutes.tsx`), chunks absents du bundle principal. Hors `AppLayout` (pas de lecteur).
- Entrée visible staff seulement : « Administration » dans le rail (PC) et section dans Paramètres (téléphone).
- Tableau de bord (cartes ; blocs `alerts`/`announcements` null → masqués), liste (recherche avec anti-rebond, filtres rôle/statut dans l'URL, pagination curseur), détail (compteurs, dernière connexion, dernière suspension, signalements/alertes ouverts, e-mail absent pour un modérateur).
- Suspendre / réactiver / changer le rôle : boîte de confirmation, motif obligatoire (1-500) y compris pour le rôle, erreurs API en français dans la boîte. Boutons masqués selon ADR 013 §2 (`permissions.ts`, testé). 403 API → « Accès réservé » (`AdminError`).
- API : `adminApi.ts` (types depuis `schema.d.ts`), `queries.ts`. Tests : `admin.test.tsx` (9).

## Pour W-74 / W-75
- Ajouter la route dans `adminRoutes.tsx` (enfants de `admin`) et l'entrée dans `adminNav.ts`. `ComingSoon.tsx` = page provisoire. W-74 a déjà branché `reports`.
- `permissions.ts` (`isStaff`, `isAdmin`, `canSuspend`, `canSetRole`) réutilisable.

## Décisions prises
- Motif obligatoire aussi pour le changement de rôle (le contrat le laisse facultatif).
- `DELETE …/suspension` envoie toujours `{note}` (à reporter dans openapi si pas déjà fait, cf. w71).

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Aucun contrôle navigateur (pas de capture) ; pas de handlers MSW admin pour le mode démo (tests : handlers en ligne).
- Pas de lien vers le journal de l'utilisateur (W-75).

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` : VERDICT OK (239 tests).
