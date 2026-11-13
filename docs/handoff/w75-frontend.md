# W-75 frontend : journal, alertes, annonces

## Fait
- `frontend/src/features/admin/activity/` : `UserActivityPage` (`/admin/users/:userId/activity`, filtre catégorie, pagination par curseur) et `AuditLogPage` (`/admin/audit-log`), liste commune `EventList` (préfixe IP et agent utilisateur seulement, métadonnées en texte brut, acteur/sujet liés à la fiche).
- `alerts/AlertsPage` (`/admin/alerts`) : filtres statut (ouvertes par défaut) / gravité / règle, acquittement avec note obligatoire, `already_acknowledged` (409) traduit.
- `announcements/AnnouncementsPage` (`/admin/announcements`) : liste (programmée / active / terminée), création, modification (sélecteurs `datetime-local`, fin > début vérifiée), suppression confirmée.
- `announcements/AnnouncementBanner` : bandeau dans `AppLayout` (sous `VerifyEmailBanner`) depuis `GET /announcements`, texte brut (React, aucun HTML), fermeture mémorisée par `id:updatedAt` dans `localStorage` (try/catch, 50 entrées max, `dismissed.ts`).
- Branchements minimaux : `adminRoutes.tsx` (4 routes lazy avant le `*`), `adminNav.ts` (3 entrées `adminOnly`), `UserDetailPage.tsx` (ligne « Journal » visible admin seulement), `AppLayout.tsx` (2 lignes).
- Tests : `announcements/announcements.test.tsx` (4).

## Décisions prises
- API locale par fonctionnalité (`activityApi.ts`, `alertsApi.ts`, `announcementsApi.ts`), `endpoints.ts` non touché.
- Le journal et les annonces vides côté filtre `actorId` de l'audit-log : non exposé en UI (l'API le supporte).
- Bandeau en `role="alert"` pour `warning`, `status` pour `info`.

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Aucun contrôle navigateur (pas de capture) : rendu clair/sombre non vérifié à l'œil.
- Pas de lien « alerte → journal filtré par `subjectIpHash` » (l'API ne filtre pas le journal par hash).
- Le bandeau s'appuie sur `/announcements` à chaque chargement (cache 5 min), pas de rafraîchissement en direct.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` : VERDICT OK (243 tests).
