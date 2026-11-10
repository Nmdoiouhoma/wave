# lot1b — qa-security (W-47/W-48, FB-18 audiences, commits 42d0166..0732061)

## Fait
- Revue : TrackAudience + tous ses appelants (voter, lookups, stream, listes user/artiste, recherche, likes, playlists items/couverture, posts lookup/liste/création/suppression/clip), close friends (processeur, provider, repository, migration), cache HTTP. Aucune route qui expose un morceau sans passer par TrackAudience (ou le filtre public seul, voulu : `artist.track_count`, trending).
- Tests ajoutés `backend/tests/Security/TrackAudienceGapsTest.php` (5, verts) : `/users/{u}/posts` par audience × appelant ; clip d'un tiers retiré quand le propriétaire passe public→followers (404 identique, auteur le voit encore) ; `Cache-Control: no-store, private` sur réponses restreintes ; PATCH/DELETE/DELETE cover non propriétaire = 404 identique (étranger) / 403 (abonné), audience inconnue refusée ; ami proche suspendu = 404 identique à inconnu, `scope` malformé = 400, usernames hostiles 404/422.
- Critique : 0 · Élevé : 0.

## Findings
- **Moyen 1** — `backend/migrations/Version20261110000200.php:44` : la FK composite `fk_close_friend_follow` n'est pas dans le mapping (`src/Entity/CloseFriend.php`) ; `doctrine:schema:update --dump-sql` propose `ALTER TABLE close_friend DROP CONSTRAINT fk_close_friend_follow`. Scénario : prochain `make:migration` relu trop vite → la FK disparaît, un désabonné reste ami proche et voit les morceaux close_friends. Correctif : `schema_filter`/listener qui ignore cette contrainte, ou test qui vérifie sa présence dans `pg_constraint`.
- **Bas 1** — `src/State/Processor/LikeProcessor.php:46` (F14) : DELETE like d'un ex-abonné sur un morceau followers/close_friends renvoie 200 + `likeCount` d'un morceau qu'il ne voit plus. Compteur seul, accepté par F14, à noter dans T17.
- **Bas 2** — `src/Repository/PlaylistRepository.php:190` + `PlaylistMapper.php:53` : la playlist d'un tiers expose `itemCount` global et des trous de `position` → un non-membre sait qu'elle contient N morceaux restreints (pas lesquels). Déjà le cas pour `private` ; documenté DATA_MODEL §playlist_item.
- **Bas 3** — `src/Security/TrackAudience.php:186` : la requête `memberships` ne part que pour un morceau followers/close_friends non possédé → écart de temps (une requête) entre id inconnu et morceau restreint. UUIDv7 non devinables : oracle théorique.
- **Bas 4** — `src/Repository/LikeRepository.php:141` : `TrackAudience::params()` lié à `likedPlaylistsPage` alors que la requête n'utilise pas `:v` (paramètre mort, sans effet).
- **Bas 5** — `docs/SECURITY.md` T7 : « FB-18 to test in W-47 » à passer en « tested » (TrackAudienceSecurityTest + TrackAudienceGapsTest).

## Décisions prises
- Aucune. Confirmé conforme à l'ADR : clip d'un tiers exige `public` (422 si visible, 404 sinon), modérateur exclu de la recherche et des clips.

## Contrat d'API modifié ? non

## Reste à faire / points ouverts
- Moyen 1 à corriger (backend) avant la prochaine migration générée.
- W-32 (écoutes `source=feed`) et W-35 (signalement) : tests d'audience à écrire dans ces lots (rappel du handoff w47).

## Commande pour vérifier
`make test c="--no-progress tests/Security/TrackAudienceGapsTest.php"` puis `bash .claude/skills/wave-verif/verif.sh back` (396 tests, 62 ignorés, VERDICT OK)
