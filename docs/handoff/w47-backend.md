# W-47 — backend (audiences followers / close_friends, liste d'amis proches)

## Fait
- Migration **nouvelle** `Version20261110000200` (W-30 intacte), jouée up/down/up en base de test seulement ; SQL en annexe. Entité `CloseFriend`, `Visibility` à 4 cas, `ProblemCode::NotAFollower` (422 `not_a_follower`).
- Règle unique `src/Security/TrackAudience.php` : fragment natif `sql()`/`visibleSql()` et son rendu DQL `dql()`/`visibleDql()` (même prédicat, `IN` non corrélés), plus contrôle PHP par lot (`trackVisibility`, `postVisibility`, une requête par page). Branché : `TrackVoter` (VIEW/STREAM, donc toutes les routes via `TrackLookup`), `StreamController` (jetons track et clip), `/users/{u}/tracks`, `/artists/{slug}/tracks`, recherche, `/me/likes/tracks`, items et couverture de playlist, posts (lookup, liste, suppression, émission de clip, création). `Track::isVisibleTo`, `Post::isPlayable/isVisibleTo` supprimés.
- `/me/close-friends` GET (scope `members`|`followers`, curseur lié au scope), PUT/DELETE `{username}` ; PUT limité par `social_write_user`. Ajout = INSERT conditionnel à la ligne follow, la FK composite `close_friend → follow ON DELETE CASCADE` couvre la course : se désabonner retire l'ami proche, se réabonner ne le rend pas.
- `TrackView.visibility` : valeur réelle pour propriétaire/modérateur, sinon `public` (morceau public) ou `null` ; idem dans `Post.track`.
- Tests : `tests/Security/TrackAudienceSecurityTest.php` (O/F/C/S/X/R/M × 4 audiences sur getTrack, stream-url, like, listens, ajout playlist, création de post, listes user/artiste/recherche, playlist d'un tiers items + couverture ; 404 comparés en-têtes + corps à un uuid inconnu ; posts followers→private→followers avec jeton clip ; jetons track après désabonnement ; masquage de visibility), `tests/Functional/Social/CloseFriendTest.php`, concurrence PUT close friend ∥ unfollow en vrais processus (`ConcurrencySecurityTest`). Suite : 391 tests (62 ignorés), PHPStan propre.
- EXPLAIN (10 k morceaux, 1 k follows, 100 amis proches, fixture annulée) : hashed SubPlans sur follow et close_friend dans les deux requêtes ; annexe.

## Décisions prises
- Modérateur : voit tout via `/tracks`, listes et playlists, mais **pas** dans la recherche (découverte = audience seule) ni pour créer un clip (règle des clips, comme n'importe qui).
- `artist.trackCount` et l'index trending restent sur les seuls morceaux publics (identiques pour tous).
- Index FK générés `IDX_` sur owner_id/friend_id omis (redondants avec la PK et l'index (friend_id, owner_id)).
- Le `down` repasse followers/close_friends en `private` (seule valeur sûre sous l'ancien CHECK).

## Contrat d'API modifié ? non (implémente le contrat FB-18 tel quel). Questions remontées, pas tranchées :
- `Post.track` n'est réduit que sur `visibility` : le schéma exige un `Track` complet. Le réduire davantage (qa phase 3) demande un changement de contrat.
- Signalement (W-35) et écoutes `source=feed` (W-32) absents : leurs tests d'audience restent à écrire dans ces lots (W-32 doit appeler `TrackAudience::isPostPlayable`).

## Reste à faire / points ouverts
- Piège : les workers de concurrence démarrent le kernel test **non debug** ; après modification des sources, `php bin/console cache:clear --env=test --no-debug` sinon 500 (vu pendant ce lot). À ajouter aux leçons de wave-verif.
- Migration à appliquer en dev par le lead après relecture.

## Commande pour vérifier
`make test c="--no-progress tests/Security/TrackAudienceSecurityTest.php tests/Functional/Social/CloseFriendTest.php"` puis `bash .claude/skills/wave-verif/verif.sh back`

---
## Annexe A — SQL de `Version20261110000200`
```sql
-- up
ALTER TABLE track DROP CONSTRAINT chk_track_visibility;
ALTER TABLE track ADD CONSTRAINT chk_track_visibility CHECK (visibility IN ('public','followers','close_friends','private'));
CREATE TABLE close_friend (owner_id UUID NOT NULL, friend_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (owner_id, friend_id), CONSTRAINT chk_close_friend_not_self CHECK (owner_id <> friend_id));
CREATE INDEX idx_close_friend_friend_owner ON close_friend (friend_id, owner_id);
ALTER TABLE close_friend ADD CONSTRAINT FK_3EAFE6C67E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE close_friend ADD CONSTRAINT FK_3EAFE6C66A5458E8 FOREIGN KEY (friend_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE close_friend ADD CONSTRAINT fk_close_friend_follow FOREIGN KEY (friend_id, owner_id) REFERENCES follow (follower_id, followed_id) ON DELETE CASCADE NOT DEFERRABLE;
-- down
DROP TABLE close_friend;
UPDATE track SET visibility = 'private' WHERE visibility IN ('followers','close_friends');
ALTER TABLE track DROP CONSTRAINT chk_track_visibility;
ALTER TABLE track ADD CONSTRAINT chk_track_visibility CHECK (visibility IN ('public','private'));
```

## Annexe B — EXPLAIN ANALYZE (auto_explain sur les vraies requêtes, fixture 10 k / 1 k annulée)
`GET /users/{u}/tracks` (requête DQL générée, viewer ≠ propriétaire) — 0,29 ms :
```
Index Scan using idx_track_owner_created on track t0_  (actual rows=4)
  Filter: (owner_id = $v OR (status='ready' AND moderation='visible' AND (visibility='public' OR owner_id = $v
          OR (visibility='followers' AND (hashed SubPlan 1)) OR (visibility='close_friends' AND (hashed SubPlan 2)))))
  SubPlan 1 -> Seq Scan on follow f4_ (actual rows=1000 loops=1)   Filter: (follower_id = $v)
  SubPlan 2 -> Seq Scan on close_friend c5_ (actual rows=100 loops=1)   Filter: (friend_id = $v)
```
`GET /search?type=tracks&q=song` (10 k candidats) — 28 ms :
```
Seq Scan on track t  (actual rows=5100)   Rows Removed by Filter: 4900
  Filter: (status='ready' AND moderation='visible' AND (visibility='public' OR owner_id = $v
          OR (visibility='followers' AND (hashed SubPlan 3)) OR (visibility='close_friends' AND (hashed SubPlan 4))))
  SubPlan 3 -> Seq Scan on follow (actual rows=1000 loops=1)
  SubPlan 4 -> Seq Scan on close_friend (actual rows=100 loops=1)
```
