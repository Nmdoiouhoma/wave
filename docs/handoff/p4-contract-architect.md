## Fait
- ADR 014 catalogue Deezer (FB-06) et ADR 015 repost (FB-33), statut proposé ; note d'amendement dans ADR 005.
- openapi : `/catalog/artists`, `/catalog/charts/artists`, `/catalog/artists/{externalId}`, `/discover`, `/posts/{postId}/repost` (PUT/DELETE), `/users/{username}/reposts`, `/admin/dashboard/series` ; `/feed/following` redéfini ; schémas Catalog*, DiscoverHome, AdminDashboardSeries, RepostState, réponse 503 `ServiceUnavailable`.
- DATA_MODEL §9b repost (+ `post.repost_count`, enum/CHECK notification, §13b), §18 lectures sans table ; CONVENTIONS §7 `CatalogProvider`, §9 `CATALOG_PROVIDER`.
- TICKETS phase 4 réécrite : W-40..W-44 amendés, nouveaux W-80..W-87, ordre de lancement.
- Compatibilité front minimale (hors périmètre, pour que verif passe) : `case 'repost'` dans `notificationText.ts`, `features: { catalog: false }` dans `mocks/db.ts`.

## Décisions prises
- Catalogue sur des routes `/catalog/*` séparées, jamais dans `/search` ; drapeau `CATALOG_PROVIDER=none` par défaut (404 + `Me.features.catalog=false`) ; panne Deezer = section absente (200 `available:false`) ou 503 sur la page artiste, jamais 500 ; cache 1 h / 24 h métadonnées / 10 min extraits (URL signées).
- CSP : `https://*.dzcdn.net` en `img-src` et `media-src` du front seulement (W-85).
- Repost : seulement les posts à audience publique, pas les siens ; filtre en direct à la lecture ; notification `repost` ; vérif. e-mail exigée ; Abonnements = un post une fois, à sa dernière activité.
- Séries admin : un seul appel, jours Europe/Paris, source `activity_event`, modérateur = série modération seulement.
- `/discover` : un appel, 4 sections publiques seulement (ADR 012 §6).

## Contrat d'API modifié ? (oui/non + détail)
- Oui. Champs requis ajoutés : `Post.repostCount/repostedByMe/canRepost/repostedBy/repostedAt`, `Me.features` ; `NotificationType.repost` ; ProblemCode `cannot_repost_own`, `repost_not_allowed`, `catalog_unavailable` ; curseurs de `/feed/following` invalidés (400 puis redémarrage).

## Reste à faire / points ouverts
- À faire confirmer par l'utilisateur : ADR 014 (drapeau éteint en prod ; catalogue hors recherche Wave) et ADR 015 (repost des posts publics seulement).
- Les mocks MSW des posts n'ont pas encore les champs repost (W-81). Ordre : voir TICKETS « Launch order » ; W-40 et W-41 ne peuvent plus tourner en parallèle (un seul PHPUnit).

## Commande pour vérifier
- `cd frontend && npm run -s gen:api && cd .. && bash .claude/skills/wave-verif/verif.sh front` → VERDICT OK (vérifié).
