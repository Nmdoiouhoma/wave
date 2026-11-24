# Handoff fb-1124 — frontend

Verdict `wave-verif front` : OK (299 tests). Aucun changement de contrat.

## Fait
1. `discover/TrackCards.tsx` : sous `@xl`, cartes `w-[calc((100cqw-1rem)/2)]` (2 entières par page) ; `snap-mandatory` partout (plus `snap-proximity` en dessous de `@xl`).
2. `catalog/CatalogSections.tsx` : `Block` reçoit `unavailableNote` ; la recherche affiche « Le catalogue Deezer est indisponible pour le moment. » quand `available:false` ou erreur (503, réseau). `rate_limited` garde son alerte. Flag off : bloc non monté, rien affiché. « Artistes célèbres » (Découvrir) inchangé.
3. `feed/FeedItem.tsx` : la pochette de repli est contenue au-dessus d'une bande basse réservée (`pb-56`, taille `min(75cqw, 24rem, 100cqh)`) → plus de chevauchement avec « Reposté par ».
4. Audience : le schéma `Post` n'a PAS de champ d'audience. Seul `Post.track.visibility` existe (réelle pour l'auteur/modérateurs, `null` = piste restreinte pour les autres, ADR 012). Badge sur cette base : Abonnés / Amis proches / Privé, et « Audience restreinte » si `null` ; rien si `public`. Si le lead veut une audience exacte pour tous, il faut un champ `Post.audience` côté contrat.
5. `admin/activity/activityApi.ts` : `eventTypeLabel()` (34 codes DATA_MODEL §15 traduits, code brut en repli et en `title` au survol) ; utilisé dans `EventList.tsx` (journal d'audit et activité d'un utilisateur).
6. `admin/SupervisionPanel.tsx` : carte Connexions non mesurée → texte secondaire « Trop peu de connexions pour évaluer » (plus de gros chiffre) ; test `admin.test.tsx` adapté.

## Reste
- Pas de test ajouté pour le badge d'audience ni le message Deezer (à demander si besoin).
- À vérifier à l'œil : fil à 1000 px (pochette) et Découvrir à 800 px avec le rail.
