# Phase 2 — backend (W-20, W-21, W-22, W-23, W-27)

## Fait
- W-20 playlists : migration 000800, Entity Playlist/PlaylistItem, PlaylistRepository, PlaylistVoter, PlaylistView/PlaylistItemView/PlaylistPage/PlaylistItemPage, PlaylistProcessor/PlaylistItemProcessor, PlaylistProvider/PlaylistListProvider/PlaylistItemsProvider/PlaylistLookup, PlaylistMapper, Dto PlaylistInput/AddPlaylistItemInput/ReorderPlaylistInput, tests/Functional/Playlist.
- W-21 follow : migration 000900, Entity Follow, FollowRepository, FollowStateView, FollowProcessor, FollowListProvider, UserListItem/UserPage, UserMapper::toListItems, UserProfileView (listFollowers/listFollowing, isFollowedByMe), tests/Functional/Social/FollowTest.
- W-22 likes : migration 001000, Entity TrackLike/PlaylistLike, LikeRepository, LikeStateView, LikeProcessor, TrackMapper::toViews (likedByMe en lot), TrackView listMyLikedTracks, TrackListProvider (branche likes), listMyLikedPlaylists, tests/Support/QueryCounter + WaveApiTestCase::countQueries, LikeTest (nb de requêtes identique limit=2 vs 6).
- W-23 écoutes : migration 001100 (listen + lock_keys), Entity Listen, src/Listen (ListenRules, ListenRecorder), ListenInput, ListenResultView, ListenProcessor, src/Recommendation/AffinityTrigger, RecomputeAffinityHandler (stub), ListenTest, Unit/Listen/ListenRulesTest.
- W-27 recherche : src/Search/*, SearchResultsView, SearchResultsProvider, tests/Functional/Search/UserSearchTest.
- Transverse : rate_limiter.yaml + RateLimitListener (social_write 200/h, listen 120/min, search 60/min + 1000/j), monolog test niveau warning, doctrine schema_filter lock_keys, services.yaml (dédup Messenger), PHPStan (lignes DBAL mappées/castées), symfony/* 7.4.19-21 → 7.4.22 (CVE-2026-45070 symfony/mime).

## Décisions prises
- DeduplicateStamp : store de verrous DoctrineDbalStore (table lock_keys, hors schéma Doctrine) au lieu de flock (clé non sérialisable).
- Écoutes : verrou advisory par (user, track), play_count+1 dans la même transaction ; source=feed → 422 en phase 2 ; pas de FK listen.post_id (à poser en W-30).
- Recherche : réponse `{users: UserPage}`, curseur lié à lower(q) (offset plafonné à 200).
- Index follow/likes à 3 colonnes (created_at DESC, id DESC) pour le keyset — écart avec DATA_MODEL.
- composer bump : contrainte symfony/flex "^2" → "^2.12".

## Contrat d'API modifié ? (oui/non + détail)
- Non. Points à trancher par l'architecte : forme `{users: …}` de /search ; listes followers/following incluent les comptes suspendus.

## Reste à faire / points ouverts
- RecomputeAffinity : handler no-op jusqu'à W-40. FK listen.post_id en W-30.
- Course de likes concurrents : test à qa (W-26).

## Commande pour vérifier
- `make test c=--no-progress` → 299 tests, 3112 assertions, 62 skipped, OK. PHPStan 8 : 0 erreur. CS : 0 fichier. `make audit` → exit=0.

## Annexe — SQL des migrations Version20261029000800..001100 (up)
```sql
-- Doctrine Migration File Generated on 2026-11-01 07:11:46

-- Version DoctrineMigrations\Version20261029000800
CREATE TABLE playlist (
    id UUID NOT NULL,
    owner_id UUID NOT NULL,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(300) DEFAULT NULL,
    is_public BOOLEAN DEFAULT true NOT NULL,
    moderation VARCHAR(16) DEFAULT 'visible' NOT NULL,
    item_count INT DEFAULT 0 NOT NULL,
    like_count INT DEFAULT 0 NOT NULL,
    search_vector TSVECTOR DEFAULT '' NOT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_playlist_name CHECK (char_length(name) >= 1),
    CONSTRAINT chk_playlist_moderation CHECK (moderation IN ('visible', 'hidden')),
    CONSTRAINT chk_playlist_item_count CHECK (item_count >= 0 AND item_count <= 500),
    CONSTRAINT chk_playlist_like_count CHECK (like_count >= 0)
);
CREATE INDEX idx_playlist_owner_created ON playlist (owner_id, created_at DESC, id DESC);
CREATE INDEX IDX_D782112D7E3C61F9 ON playlist (owner_id);
CREATE INDEX idx_playlist_search ON playlist USING GIN (search_vector);
ALTER TABLE playlist ADD CONSTRAINT FK_D782112D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE FUNCTION wave_playlist_search_vector() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    NEW.search_vector :=
        setweight(to_tsvector('simple', wave_unaccent(lower(NEW.name))), 'A')
        || setweight(to_tsvector('simple', wave_unaccent(lower(coalesce(NEW.description, '')))), 'B');
    RETURN NEW;
END $$;
CREATE TRIGGER trg_playlist_search_vector BEFORE INSERT OR UPDATE OF name, description ON playlist FOR EACH ROW EXECUTE FUNCTION wave_playlist_search_vector();
CREATE TABLE playlist_item (
    id UUID NOT NULL,
    playlist_id UUID NOT NULL,
    track_id UUID NOT NULL,
    position INT NOT NULL,
    added_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_playlist_item_position CHECK (position >= 0)
);
CREATE UNIQUE INDEX uniq_playlist_item_track ON playlist_item (playlist_id, track_id);
ALTER TABLE playlist_item ADD CONSTRAINT uniq_playlist_item_position UNIQUE (playlist_id, position) DEFERRABLE INITIALLY DEFERRED;
CREATE INDEX IDX_BF02127C6BBD148 ON playlist_item (playlist_id);
CREATE INDEX IDX_BF02127C5ED23C43 ON playlist_item (track_id);
ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C6BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE;
-- Version DoctrineMigrations\Version20261029000800 update table metadata;
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261029000800', '2026-11-01 07:11:46', 0);
-- Doctrine Migration File Generated on 2026-11-01 07:11:47

-- Version DoctrineMigrations\Version20261029000900
CREATE TABLE follow (
    follower_id UUID NOT NULL,
    followed_id UUID NOT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    PRIMARY KEY (follower_id, followed_id),
    CONSTRAINT chk_follow_not_self CHECK (follower_id <> followed_id)
);
CREATE INDEX idx_follow_followed_created ON follow (followed_id, created_at DESC, follower_id DESC);
CREATE INDEX idx_follow_follower_created ON follow (follower_id, created_at DESC, followed_id DESC);
CREATE INDEX IDX_68344470AC24F853 ON follow (follower_id);
CREATE INDEX IDX_68344470D956F010 ON follow (followed_id);
ALTER TABLE follow ADD CONSTRAINT FK_68344470AC24F853 FOREIGN KEY (follower_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE follow ADD CONSTRAINT FK_68344470D956F010 FOREIGN KEY (followed_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
-- Version DoctrineMigrations\Version20261029000900 update table metadata;
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261029000900', '2026-11-01 07:11:47', 0);
-- Doctrine Migration File Generated on 2026-11-01 07:11:48

-- Version DoctrineMigrations\Version20261029001000
CREATE TABLE track_like (user_id UUID NOT NULL, track_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id, track_id));
CREATE INDEX idx_track_like_track ON track_like (track_id);
CREATE INDEX idx_track_like_user_created ON track_like (user_id, created_at DESC, track_id DESC);
CREATE INDEX IDX_313568B6A76ED395 ON track_like (user_id);
ALTER TABLE track_like ADD CONSTRAINT FK_313568B6A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE track_like ADD CONSTRAINT FK_313568B65ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE playlist_like (user_id UUID NOT NULL, playlist_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id, playlist_id));
CREATE INDEX idx_playlist_like_playlist ON playlist_like (playlist_id);
CREATE INDEX idx_playlist_like_user_created ON playlist_like (user_id, created_at DESC, playlist_id DESC);
CREATE INDEX IDX_C7A77D1A76ED395 ON playlist_like (user_id);
ALTER TABLE playlist_like ADD CONSTRAINT FK_C7A77D1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE playlist_like ADD CONSTRAINT FK_C7A77D16BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE;
-- Version DoctrineMigrations\Version20261029001000 update table metadata;
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261029001000', '2026-11-01 07:11:48', 0);
-- Doctrine Migration File Generated on 2026-11-01 07:11:49

-- Version DoctrineMigrations\Version20261029001100
CREATE TABLE listen (
    id UUID NOT NULL,
    user_id UUID NOT NULL,
    track_id UUID NOT NULL,
    post_id UUID DEFAULT NULL,
    source VARCHAR(16) NOT NULL,
    listened_ms INT NOT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    PRIMARY KEY (id),
    CONSTRAINT chk_listen_source CHECK (source IN ('player', 'feed')),
    CONSTRAINT chk_listen_ms CHECK (listened_ms >= 0)
);
CREATE INDEX idx_listen_user_created ON listen (user_id, created_at DESC);
CREATE INDEX idx_listen_track_created ON listen (track_id, created_at DESC);
CREATE INDEX idx_listen_user_track_created ON listen (user_id, track_id, created_at DESC);
CREATE INDEX IDX_C22467FDA76ED395 ON listen (user_id);
CREATE INDEX IDX_C22467FD5ED23C43 ON listen (track_id);
ALTER TABLE listen ADD CONSTRAINT FK_C22467FDA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE listen ADD CONSTRAINT FK_C22467FD5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id));
-- Version DoctrineMigrations\Version20261029001100 update table metadata;
INSERT INTO doctrine_migration_versions (version, executed_at, execution_time) VALUES ('DoctrineMigrations\Version20261029001100', '2026-11-01 07:11:49', 0);
```
