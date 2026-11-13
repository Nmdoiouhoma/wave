## Fait
- W-74 (absorbe W-50) dans `frontend/src/features/admin/moderation/` : `ReportQueuePage` (file groupée par cible, filtres statut et type, aperçu, motifs, compteurs), `ReportTargetPage` (signalements d'une cible, pagination), `ContentListPage` (type, modération, recherche ≥ 2 caractères, `ownerId` en query ; filtres dans l'URL).
- Décisions masquer / rétablir (`dismiss`) / suspendre / classer sans suite via une feuille de confirmation avec note facultative (500 max). Suppression : bouton visible pour l'admin seulement, note obligatoire + mot `SUPPRIMER` à taper.
- Mises à jour optimistes avec retour arrière (file, liste de contenus) ; 403/404/409/422 en français (`moderationLabels.ts`).
- API locale `moderationApi.ts` (sans toucher `src/api/endpoints.ts`), types issus de `schema.d.ts`.
- Routes ajoutées dans `adminRoutes.tsx` (seul fichier W-73 touché, bloc W-74 remplacé) : `reports`, `reports/:targetType/:targetId`, `content` (lazy). Entrées de menu déjà présentes.
- Tests : `moderation.test.tsx` (5).

## Décisions prises
- Pas de `GET` d'une cible seule : la page détail reprend la carte via l'état du lien (file) ou le cache de la dernière décision ; sinon elle affiche seulement l'identifiant.
- Un seul bouton « Rétablir » = `dismiss` (contrat openapi).

## Contrat d'API modifié ? non
- Souhaitable (architecte) : `GET /admin/report-targets/{type}/{id}` pour ouvrir le détail par lien direct avec l'aperçu.

## Reste à faire / points ouverts
- Aucun contrôle navigateur fait (pas de capture) : rendu clair/sombre non vérifié à l'œil.
- Pas de lien vers la fiche utilisateur depuis le propriétaire (`/admin/users/:id`), à ajouter si souhaité.

## Commande pour vérifier
`bash .claude/skills/wave-verif/verif.sh front` : VERDICT OK (239 tests).
