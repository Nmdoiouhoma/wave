<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W-30: every phase-3 table (DATA_MODEL §8, §8b, §9, §10, §11, §13): post, hashtag, post_hashtag,
 * post_like, comment, post_view, report, notification, notification_actor, and the listen.post_id FK.
 * Hand-written (remove them from any `migrations:diff` output, like the GIN indexes): CHECK
 * constraints, DESC keyset indexes, hashtag prefix/trigram indexes, and the unread-notification
 * unique index (NULLS NOT DISTINCT, partial, PG >= 15).
 */
final class Version20261110000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-30: phase-3 tables (posts, hashtags, likes, comments, views, reports, notifications)';
    }

    public function up(Schema $schema): void
    {
        // post (§9)
        $this->addSql(<<<'SQL'
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
            )
            SQL);
        $this->addSql('CREATE INDEX idx_post_author_created ON post (author_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX idx_post_track ON post (track_id)');
        $this->addSql("CREATE INDEX idx_post_ready_created ON post (created_at DESC, id DESC) WHERE (((status)::text = 'ready'::text) AND ((moderation)::text = 'visible'::text))");
        $this->addSql("CREATE INDEX idx_post_ready_trending ON post (trending_score DESC, id DESC) WHERE (((status)::text = 'ready'::text) AND ((moderation)::text = 'visible'::text))");
        $this->addSql('CREATE INDEX IDX_5A8A6C8DF675F31B ON post (author_id)');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8DF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT FK_5A8A6C8D5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');

        // hashtag, post_hashtag (§9)
        $this->addSql(<<<'SQL'
            CREATE TABLE hashtag (
                id UUID NOT NULL, post_count INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                name VARCHAR(50) NOT NULL, PRIMARY KEY (id),
                CONSTRAINT chk_hashtag_name CHECK (char_length(name) >= 1 AND name = lower(name) AND name !~ '[#[:space:]]'),
                CONSTRAINT chk_hashtag_post_count CHECK (post_count >= 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_hashtag_name ON hashtag (name)');
        $this->addSql('CREATE INDEX idx_hashtag_name_prefix ON hashtag (name varchar_pattern_ops)');
        $this->addSql('CREATE INDEX idx_hashtag_name_trgm ON hashtag USING GIN (name gin_trgm_ops)');
        $this->addSql('CREATE TABLE post_hashtag (post_id UUID NOT NULL, hashtag_id UUID NOT NULL, PRIMARY KEY (post_id, hashtag_id))');
        $this->addSql('CREATE INDEX idx_post_hashtag_hashtag ON post_hashtag (hashtag_id, post_id)');
        $this->addSql('CREATE INDEX IDX_675D9D524B89032C ON post_hashtag (post_id)');
        $this->addSql('CREATE INDEX IDX_675D9D52FB34EF56 ON post_hashtag (hashtag_id)');
        $this->addSql('ALTER TABLE post_hashtag ADD CONSTRAINT FK_675D9D524B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE post_hashtag ADD CONSTRAINT FK_675D9D52FB34EF56 FOREIGN KEY (hashtag_id) REFERENCES hashtag (id) ON DELETE CASCADE NOT DEFERRABLE');

        // post_like (§8)
        $this->addSql('CREATE TABLE post_like (created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, post_id UUID NOT NULL, PRIMARY KEY (user_id, post_id))');
        $this->addSql('CREATE INDEX idx_post_like_post ON post_like (post_id)');
        $this->addSql('CREATE INDEX idx_post_like_user_created ON post_like (user_id, created_at DESC, post_id DESC)');
        $this->addSql('CREATE INDEX IDX_653627B8A76ED395 ON post_like (user_id)');
        $this->addSql('ALTER TABLE post_like ADD CONSTRAINT FK_653627B8A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE post_like ADD CONSTRAINT FK_653627B84B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');

        // comment (§10)
        $this->addSql(<<<'SQL'
            CREATE TABLE comment (
                id UUID NOT NULL, moderation VARCHAR(16) DEFAULT 'visible' NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, body VARCHAR(500) NOT NULL,
                post_id UUID NOT NULL, author_id UUID NOT NULL, PRIMARY KEY (id),
                CONSTRAINT chk_comment_body CHECK (char_length(btrim(body)) >= 1),
                CONSTRAINT chk_comment_moderation CHECK (moderation IN ('visible', 'hidden'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_comment_post_created ON comment (post_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX IDX_9474526C4B89032C ON comment (post_id)');
        $this->addSql('CREATE INDEX IDX_9474526CF675F31B ON comment (author_id)');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526C4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE comment ADD CONSTRAINT FK_9474526CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // post_view (§11)
        $this->addSql(<<<'SQL'
            CREATE TABLE post_view (
                watched_ms INT DEFAULT 0 NOT NULL, completed BOOLEAN DEFAULT false NOT NULL,
                first_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, last_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                user_id UUID NOT NULL, post_id UUID NOT NULL, PRIMARY KEY (user_id, post_id),
                CONSTRAINT chk_post_view_watched CHECK (watched_ms >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_post_view_post ON post_view (post_id)');
        $this->addSql('CREATE INDEX idx_post_view_user_seen ON post_view (user_id, last_seen_at, post_id)');
        $this->addSql('CREATE INDEX IDX_37A8CC85A76ED395 ON post_view (user_id)');
        $this->addSql('ALTER TABLE post_view ADD CONSTRAINT FK_37A8CC85A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE post_view ADD CONSTRAINT FK_37A8CC854B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');

        // report (§13)
        $this->addSql(<<<'SQL'
            CREATE TABLE report (
                id UUID NOT NULL, status VARCHAR(16) DEFAULT 'open' NOT NULL,
                handled_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, decision_note VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, target_type VARCHAR(16) NOT NULL,
                target_id UUID NOT NULL, reason VARCHAR(16) NOT NULL, details VARCHAR(1000) DEFAULT NULL,
                handled_by_id UUID DEFAULT NULL, reporter_id UUID NOT NULL, PRIMARY KEY (id),
                CONSTRAINT chk_report_target_type CHECK (target_type IN ('track', 'post', 'comment', 'playlist', 'artist', 'user')),
                CONSTRAINT chk_report_reason CHECK (reason IN ('spam', 'harassment', 'hate', 'sexual', 'violence', 'copyright', 'impersonation', 'other')),
                CONSTRAINT chk_report_status CHECK (status IN ('open', 'actioned', 'dismissed'))
            )
            SQL);
        $this->addSql('CREATE INDEX idx_report_status_created ON report (status, created_at)');
        $this->addSql('CREATE INDEX idx_report_target_status ON report (target_type, target_id, status)');
        $this->addSql('CREATE UNIQUE INDEX uniq_report_reporter_target ON report (reporter_id, target_type, target_id)');
        $this->addSql('CREATE INDEX IDX_C42F7784FE65AF40 ON report (handled_by_id)');
        $this->addSql('CREATE INDEX IDX_C42F7784E1CFE6F5 ON report (reporter_id)');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784FE65AF40 FOREIGN KEY (handled_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE report ADD CONSTRAINT FK_C42F7784E1CFE6F5 FOREIGN KEY (reporter_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // notification, notification_actor (§8b)
        $this->addSql(<<<'SQL'
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
            )
            SQL);
        $this->addSql('CREATE INDEX idx_notification_recipient_updated ON notification (recipient_id, updated_at DESC, id DESC)');
        $this->addSql('CREATE UNIQUE INDEX uniq_notification_unread ON notification (recipient_id, type, track_id, playlist_id, post_id) NULLS NOT DISTINCT WHERE read_at IS NULL');
        $this->addSql('CREATE INDEX IDX_BF5476CA5ED23C43 ON notification (track_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA6BBD148 ON notification (playlist_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CA4B89032C ON notification (post_id)');
        $this->addSql('CREATE INDEX IDX_BF5476CAE92F8F78 ON notification (recipient_id)');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA6BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CA4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification ADD CONSTRAINT FK_BF5476CAE92F8F78 FOREIGN KEY (recipient_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('CREATE TABLE notification_actor (created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, notification_id UUID NOT NULL, actor_id UUID NOT NULL, PRIMARY KEY (notification_id, actor_id))');
        $this->addSql('CREATE INDEX idx_notification_actor_actor ON notification_actor (actor_id)');
        $this->addSql('CREATE INDEX IDX_82C1B300EF1A9D84 ON notification_actor (notification_id)');
        $this->addSql('ALTER TABLE notification_actor ADD CONSTRAINT FK_82C1B300EF1A9D84 FOREIGN KEY (notification_id) REFERENCES notification (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE notification_actor ADD CONSTRAINT FK_82C1B30010DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');

        // listen.post_id (§11): column exists since W-11, always null so far.
        $this->addSql('CREATE INDEX IDX_C22467FD4B89032C ON listen (post_id)');
        $this->addSql('ALTER TABLE listen ADD CONSTRAINT FK_C22467FD4B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE listen DROP CONSTRAINT FK_C22467FD4B89032C');
        $this->addSql('DROP INDEX IDX_C22467FD4B89032C');
        $this->addSql('DROP TABLE notification_actor');
        $this->addSql('DROP TABLE notification');
        $this->addSql('DROP TABLE report');
        $this->addSql('DROP TABLE post_view');
        $this->addSql('DROP TABLE comment');
        $this->addSql('DROP TABLE post_like');
        $this->addSql('DROP TABLE post_hashtag');
        $this->addSql('DROP TABLE hashtag');
        $this->addSql('DROP TABLE post');
    }
}
