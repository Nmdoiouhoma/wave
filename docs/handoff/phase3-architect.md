## Fait
- `docs/TICKETS.md` phase 3 réécrite : W-38 (shell de navigation, carte des routes et onglets), W-39 (Pour toi v1), W-30 à W-37 complétés (codes d'erreur, ordre des contrôles, tests attendus) ; W-34 branché sur W-39 (MSW seulement en Vitest) ; W-40 remplace le classement de W-39 en gardant ses tests ; W-43 ne refait plus les onglets du fil.
- ADR 011 (nouveau) : `SimpleFeedRanker`, score chiffré, curseur de session, « vu » = ligne `post_view`. ADR 005 renvoie vers lui. CONVENTIONS §2 et §6 alignés.
- DATA_MODEL §9 à §13 : post visible/jouable, nettoyage à la suppression, commentaire visible, upsert `post_view`, écoutes du fil, ordre des contrôles de signalement, verrou d'auto-masquage, actions de modération permises.

## Décisions prises
- Pour toi v1 : `0.35·suivi + 0.25·genre liké + 0.25·popularité×fraîcheur + 0.10·fraîcheur + 0.05·aléa`, demi-vie 72 h ; épuisement des non-vus → déjà-vus du moins récent au plus récent, jamais deux fois le même post dans une session ; pas de table de posts vus (index `(user_id, last_seen_at, post_id)` ajouté sur `post_view`).
- Toutes les tables de la phase 3 sont créées par une seule migration dans W-30, pour que W-32, W-33 et W-39 tournent en parallèle sans migration.
- Posts d'auteurs suspendus : non jouables (404, absents des fils) ; commentaires d'auteurs suspendus masqués dans les listes (compteurs inchangés jusqu'à la phase 5, comme F16). Les morceaux des propriétaires suspendus restent visibles (F18, phase 5).
- Émettre `Post.clip` crée l'autorisation d'écoute (ADR 010) : c'est ce qui permet de compter les écoutes depuis le fil (`source=feed`, W-32).
- Interface : le fil possède le son (le lecteur global se met en pause, le mini-lecteur est caché sur `/`, `/following` et `/posts/:id`) ; Découvrir = recherche d'utilisateurs en phase 3 ; `/search` redirige vers `/discover`.

## Contrat d'API modifié ? oui (précisions, aucun schéma changé)
- **Correction de sécurité** : `POST /posts` sur le morceau privé ou masqué d'un autre → 404 (avant : 422 `clips_not_allowed`, ce qui révélait que le morceau existait, T7/T17).
- Descriptions précisées : `/feed/for-you` (pages courtes possibles, fin de session, 400 au changement de classement), `/feed/following`, `/post-views` (ses propres posts ignorés, `watchedMs` plafonné), commentaires, `GET/DELETE /posts/{id}`, `/stream` (jeton de clip revérifié), `/reports` (ordre des contrôles), décision de modération (cas 422), file `/admin`, règle globale sur les comptes suspendus.

## Reste à faire / points ouverts
- **Ordre des lots corrigé** : 1 = W-38 ∥ W-30 (W-31 attend l'entité Post, la table et `tryMarkReady` de W-30) ; 2 = W-31 ∥ W-32 ∥ W-39 ; 3 = W-33 ∥ W-34 ∥ W-35 (W-35 a besoin de posts `ready`, donc de W-31) ; 4 = W-36 (dépend des signalements de W-33), puis W-37.
- Risque résiduel pour qa (T20) : une page du fil crée jusqu'à 20 autorisations d'écoute, ce qui rend comptables des écoutes du lecteur sans appel à stream-url. Les limites restent bornées par le temps écoulé et le plafond de 20 par jour.
- Pas de désuspension avant W-50 (phase 5). `SECURITY.md` est laissé à W-37.

## Commande pour vérifier
`ruby -ryaml -e 'YAML.load_file("docs/openapi.yaml")'` · `cd frontend && npm run check:api` (régénérer `schema.d.ts` si seules des descriptions diffèrent) · `sed -n '/^## Phase 3/,/^## Phase 4/p' docs/TICKETS.md`
