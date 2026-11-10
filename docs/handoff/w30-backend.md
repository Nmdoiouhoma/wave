# W-30 — Posts, backend

## Fait
- Migration `Version20261110000100` : 9 tables phase 3 (post, hashtag, post_hashtag, post_like, comment, post_view, report, notification, notification_actor) + `listen.post_id` FK SET NULL ; CHECK, index partiels, GIN trigramme, `uniq_notification_unread … NULLS NOT DISTINCT`. Up/down/up passés en base de test uniquement.
- `POST /posts` (202 + `GenerateClip`/`ProcessPostVisual` sur `media`), `GET /posts/{id}`, `GET /users/{u}/posts`, `GET /posts/{id}/stream-url`, `DELETE /posts/{id}`, jetons `c` dans `/stream/{token}` (visibilité revérifiée à chaque requête). Limiteur `post_create_user` 30/jour.
- `PostRepository::tryMarkReady(Uuid): bool` pour W-31 (UPDATE conditionnel : clip_key + visual_key si visuel).
- `PostCleanup` (compteurs hashtags, signalements du post et de ses commentaires, `PurgeObjects`) appelé par DELETE post et par la suppression de morceau ; `beforeUserDeletion()` prêt pour DELETE /me (phase 5).
- Tests : `tests/Functional/Post/PostTest.php` (12), `tests/Security/PostSecurityTest.php` (404 octet pour octet), `createPost` ajouté à la liste des opérations bloquées pour un e-mail non vérifié.

## Décisions prises
- `tryMarkReady` est sur `PostRepository`, pas sur l'entité (une entité n'exécute pas de SQL).
- Modérateur qui poste sur le morceau non public d'un autre : 404 comme tout utilisateur (le rôle ne sert qu'à lire/supprimer).
- Visuel brut en `quarantine/posts/{id}/visual` (zone privée) ; vidéo = `ftyp` + liste de marques autorisées (premier filtre, la vraie sonde est W-31).
- Supprimer un morceau supprime aussi les signalements visant ce morceau.
- Hashtag > 50 caractères : tronqué aux 50 premiers (regex de la spec appliquée à la lettre, sans lookahead).

## Contrat d'API modifié ?
- Non.

## Reste à faire / points ouverts
- À trancher (qa/architecte) : par la formule §9, le post d'un auteur sur son propre morceau **privé** est jouable par tous → titre/artiste du morceau privé exposés.
- Handlers `GenerateClip`/`ProcessPostVisual` : W-31.
- Leçon wave-verif : après un changement de constructeur, la suite ConcurrencySecurityTest échoue en 500 (sous-processus sur conteneur de test périmé) → `make console c="cache:clear --env=test"`.

## Commande pour vérifier
- `bash .claude/skills/wave-verif/verif.sh back` (VERDICT OK au 10/11/2026 : 381 tests).

## Annexe — SQL up de Version20261110000100 (relu par le lead avant application en dev)
```sql
CREATE TABLE post (
    id UUID NOT NULL, clip_start_ms INT NOT NULL, clip_duration_ms INT NOT NULL,
    clip_key VARCHAR(255) DEFAULT NULL, visual_type VARCHAR(16) DEFAULT NULL,
    visual_raw_key VARCHAR(255) DEFAULT NULL, visual_key VARCHAR(255) DEFAULT NULL,
    visual_poster_key VARCHAR(255) DEFAULT NULL, caption VARCHAR(300) DEFAULT NULL,
    status VARCHAR(16) DEFAULT 'processing' NOT NULL, failure_reason VARCHAR(32) DEFAULT NULL,
    moderation VARCHAR(16) DEFAULT 'visible' NOT NULL, like_count INT DEFAULT 0 NOT NULL,
    comment_count INT DEFAULT 0 NOT NULL, view_count INT DEFAULT 0 NOT NULL,
    trending_score DOUBLE PRECISION DEFAULT 0 NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    author_id UUID NOT NULL, track_id UUID NOT NULL, PRIMARY KEY (id),
    CONSTRAINT chk_post_clip_start CHECK (clip_start_ms >= 0),
    CONSTRAINT chk_post_clip_duration CHECK (clip_duration_ms BETWEEN 15000 AND 60000),
    CONSTRAINT chk_post_visual_type CHECK (visual_type IS NULL OR visual_type IN ('image', 'video')),
    CONSTRAINT chk_post_status CHECK (status IN ('processing', 'ready', 'failed')),
    CONSTRAINT chk_post_failure_reason CHECK (failure_reason IS NULL OR failure_reason IN ('invalid_media', 'unsupported_codec', 'too_long', 'too_large', 'processing_error', 'no_audio')),
    CONSTRAINT chk_post_moderation CHECK (moderation IN ('visible', 'hidden')),
    CONSTRAINT chk_post_counters CHECK (like_count >= 0 AND comment_count >= 0 AND view_count >= 0)
);
CREATE INDEX idx_post_author_created ON post (author_id, created_at DESC, id DESC);
CREATE INDEX idx_post_track ON post (track_id);
CREATE INDEX idx_post_ready_created ON post (created_at DESC, id DESC) WHERE (((status)::text = 'ready'::text) AND ((moderation)::text = 'visible'::text));
CREATE INDEX idx_post_ready_trending ON post (trending_score DESC, id DESC) WHERE (((status)::text = 'ready'::text) AND ((moderation)::text = 'visible'::text));
CREATE INDEX IDX_5A8A6C8DF675F31B ON post (author_id);
ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8D5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE hashtag (
    id UUID NOT NULL, post_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    name VARCHAR(50) NOT NULL, PRIMARY KEY (id),
    CONSTRAINT chk_hashtag_name CHECK (char_length(name) >= 1 AND name = lower(name) AND name !~ '[#[:space:]]'),
    CONSTRAINT chk_hashtag_post_count CHECK (post_count >= 0)
);
CREATE UNIQUE INDEX uniq_hashtag_name ON hashtag (name);
CREATE INDEX idx_hashtag_name_prefix ON hashtag (name varchar_pattern_ops);
CREATE INDEX idx_hashtag_name_trgm ON hashtag USING GIN (name gin_trgm_ops);
CREATE TABLE post_hashtag (post_id UUID NOT NULL, hashtag_id UUID NOT NULL, PRIMARY KEY (post_id, hashtag_id));
CREATE INDEX idx_post_hashtag_hashtag ON post_hashtag (hashtag_id, post_id);
CREATE INDEX IDX_675D9D524B89032C ON post_hashtag (post_id);
CREATE INDEX IDX_675D9D52FB34EF56 ON post_hashtag (hashtag_id);
ALTER TABLE post_hashtag ADD CONSTRAINT FK_675D9D524B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE post_hashtag ADD CONSTRAINT FK_675D9D52FB34EF56 FOREIGN KEY (hashtag_id) REFERENCES hashtag (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE post_like (created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, post_id UUID NOT NULL, PRIMARY KEY (user_id, post_id));
CREATE INDEX idx_post_like_post ON post_like (post_id);
CREATE INDEX idx_post_like_user_created ON post_like (user_id, created_at DESC, post_id DESC);
CREATE INDEX IDX_653627B8A76ED395 ON post_like (user_id);
ALTER TABLE post_like ADD CONSTRAINT FK_653627B8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE post_like ADD CONSTRAINT FK_653627B84B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE comment (
    id UUID NOT NULL, moderation VARCHAR(16) DEFAULT 'visible' NOT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, body VARCHAR(500) NOT NULL,
    post_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id),
    CONSTRAINT chk_comment_body CHECK (char_length(btrim(body)) >= 1),
    CONSTRAINT chk_comment_moderation CHECK (moderation IN ('visible', 'hidden'))
);
CREATE INDEX idx_comment_post_created ON comment (post_id, created_at DESC, id DESC);
CREATE INDEX IDX_9474526C4B89032C ON comment (post_id);
CREATE INDEX IDX_9474526CF675F31B ON comment (author_id);
ALTER TABLE comment ADD CONSTRAINT FK_9474526C4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE post_view (
    watched_ms INT DEFAULT 0 NOT NULL, completed BOOLEAN DEFAULT false NOT NULL,
    first_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    user_id UUID NOT NULL, post_id UUID NOT NULL, PRIMARY KEY (user_id, post_id),
    CONSTRAINT chk_post_view_watched CHECK (watched_ms >= 0)
);
CREATE INDEX idx_post_view_post ON post_view (post_id);
CREATE INDEX idx_post_view_user_seen ON post_view (user_id, last_seen_at, post_id);
CREATE INDEX IDX_37A8CC85A76ED395 ON post_view (user_id);
ALTER TABLE post_view ADD CONSTRAINT FK_37A8CC85A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE post_view ADD CONSTRAINT FK_37A8CC854B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE report (
    id UUID NOT NULL, status VARCHAR(16) DEFAULT 'open' NOT NULL,
    handled_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, decision_note VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, target_type VARCHAR(16) NOT NULL,
    target_id UUID NOT NULL, reason VARCHAR(16) NOT NULL, details VARCHAR(1000) DEFAULT NULL,
    handled_by_id UUID DEFAULT NULL, reporter_id UUID NOT NULL, PRIMARY KEY (id),
    CONSTRAINT chk_report_target_type CHECK (target_type IN ('track', 'post', 'comment', 'playlist', 'artist', 'user')),
    CONSTRAINT chk_report_reason CHECK (reason IN ('spam', 'harassment', 'hate', 'sexual', 'violence', 'copyright', 'impersonation', 'other')),
    CONSTRAINT chk_report_status CHECK (status IN ('open', 'actioned', 'dismissed'))
);
CREATE INDEX idx_report_status_created ON report (status, created_at);
CREATE INDEX idx_report_target_status ON report (target_type, target_id, status);
CREATE UNIQUE INDEX uniq_report_reporter_target ON report (reporter_id, target_type, target_id);
CREATE INDEX IDX_C42F7784FE65AF40 ON report (handled_by_id);
CREATE INDEX IDX_C42F7784E1CFE6F5 ON report (reporter_id);
ALTER TABLE report ADD CONSTRAINT FK_C42F7784FE65AF40 FOREIGN KEY (handled_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE;
ALTER TABLE report ADD CONSTRAINT FK_C42F7784E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE notification (
    id UUID NOT NULL, read_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
    type VARCHAR(16) NOT NULL, track_id UUID DEFAULT NULL, playlist_id UUID DEFAULT NULL,
    post_id UUID DEFAULT NULL, recipient_id UUID NOT NULL, PRIMARY KEY (id),
    CONSTRAINT chk_notification_target CHECK (
        (type = 'follow' AND track_id IS NULL AND playlist_id IS NULL AND post_id IS NULL)
        OR (type = 'track_like' AND track_id IS NOT NULL AND playlist_id IS NULL AND post_id IS NULL)
        OR (type = 'playlist_like' AND playlist_id IS NOT NULL AND track_id IS NULL AND post_id IS NULL)
        OR (type IN ('post_like', 'comment') AND post_id IS NOT NULL AND track_id IS NULL AND playlist_id IS NULL)
    )
);
CREATE INDEX idx_notification_recipient_updated ON notification (recipient_id, updated_at DESC, id DESC);
CREATE UNIQUE INDEX uniq_notification_unread ON notification (recipient_id, type, track_id, playlist_id, post_id) NULLS NOT DISTINCT WHERE read_at IS NULL;
CREATE INDEX IDX_BF5476CA5ED23C43 ON notification (track_id);
CREATE INDEX IDX_BF5476CA6BBD148 ON notification (playlist_id);
CREATE INDEX IDX_BF5476CA4B89032C ON notification (post_id);
CREATE INDEX IDX_BF5476CAE92F8F78 ON notification (recipient_id);
ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA6BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE TABLE notification_actor (created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, notification_id UUID NOT NULL, actor_id UUID NOT NULL, PRIMARY KEY (notification_id, actor_id));
CREATE INDEX idx_notification_actor_actor ON notification_actor (actor_id);
CREATE INDEX IDX_82C1B300EF1A9D84 ON notification_actor (notification_id);
ALTER TABLE notification_actor ADD CONSTRAINT FK_82C1B300EF1A9D84 FOREIGN KEY (notification_id) REFERENCES notification (id) ON DELETE CASCADE NOT DEFERRABLE;
ALTER TABLE notification_actor ADD CONSTRAINT FK_82C1B30010DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE;
CREATE INDEX IDX_C22467FD4B89032C ON listen (post_id);
ALTER TABLE listen ADD CONSTRAINT FK_C22467FD4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE SET NULL NOT DEFERRABLE;
```
