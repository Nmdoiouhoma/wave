# vis-1110 — architect (FB-18, audiences des morceaux non publics)

## Fait
- ADR 012 `docs/adr/012-track-audiences.md` ; DATA_MODEL §1 (enum), §6 (règle d'audience + forme SQL), §7 (playlists, couverture), §8 (`close_friend`), §9 (post jouable dépendant de l'appelant, création), §11 (écoute feed), §13b ; note de migration en §9.
- openapi : `Visibility` à 4 valeurs, `Track.visibility` nullable, `/me/close-friends` (GET, scope members|followers) et `/me/close-friends/{username}` (PUT/DELETE), schémas `CloseFriendState/Item/Page`, code `not_a_follower` (422) ; descriptions track lists, search, createPost, getPost, ListenRequest.postId.
- TICKETS : W-47 (backend) et W-48 (frontend) en lot 1b avant la vague 2 ; W-31, W-32, W-39, W-45, W-35, W-37 mis à jour. SECURITY T7/T17 complétés.

## Décisions prises
- Une colonne `visibility` : `public`, `followers`, `close_friends`, `private` (= moi seul, pas de réécriture des données).
- Une seule liste d'amis proches par utilisateur (pas une par morceau) — **à confirmer par l'utilisateur**.
- Membres = abonnés imposé par FK composite vers `follow` ON DELETE CASCADE : se désabonner retire l'accès, se réabonner ne le rend pas.
- Post jouable pour v : (morceau public ∧ extraits) ∨ (auteur = propriétaire ∧ v dans l'audience) ; changer l'audience s'applique aux posts à la requête suivante (rien n'est réécrit). Clore le point 1 de phase3-v1-qa.
- Restent publics seulement : `artist.track_count`, index trending, découverte phase 4. Compteurs (écoutes, likes, `hashtag.post_count`) globaux, accepté.
- Non-propriétaire : `visibility` = `public` ou null (audience non divulguée).
- Perf : fragment SQL unique, `IN` non corrélés → hashed SubPlans ; nouvel index `close_friend (friend_id, owner_id)` (sert aussi la cascade).

## Contrat d'API modifié ? oui
- `Track.visibility` nullable et 4 valeurs (types front à régénérer), 3 opérations nouvelles, 1 code d'erreur.

## Reste à faire / points ouverts
- Nouvelle migration (W-47) relue par le lead avant application ; la migration W-30 n'est pas modifiée.
- « Abonnés » = quiconque clique Suivre (pas d'approbation d'abonnement en v1) : texte d'aide dans W-48.

## Commande pour vérifier
`ruby -ryaml -e 'YAML.load_file("docs/openapi.yaml")'` ; `grep -n "FB-18" docs/DATA_MODEL.md docs/TICKETS.md docs/SECURITY.md`
