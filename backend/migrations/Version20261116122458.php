<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W-80 (ADR 015, DATA_MODEL §9b) — repost.
 * Hand-written like the other social migrations: the `repost` table, the `post.repost_count`
 * counter (guarded by chk_post_counters), the DESC keyset index, and the extended
 * chk_notification_target CHECK. Every DROP of a hand-written object that `migrations:diff`
 * proposed (search indexes, uniq_notification_unread, fk_close_friend_follow,
 * idx_activity_event_*, security_alert.subject_key…) has been removed, per CLAUDE.md.
 */
final class Version20261116122458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-80: repost table, post.repost_count, notification repost target';
    }

    public function up(Schema $schema): void
    {
        // repost (ADR 015): PK(user_id, post_id), both FKs CASCADE; rows written with idempotent
        // ON CONFLICT SQL. idx_repost_user_created is DESC for the /users/{username}/reposts keyset.
        $this->addSql('CREATE TABLE repost (created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, post_id UUID NOT NULL, PRIMARY KEY (user_id, post_id))');
        $this->addSql('CREATE INDEX idx_repost_post ON repost (post_id)');
        $this->addSql('CREATE INDEX idx_repost_user_created ON repost (user_id, created_at DESC, post_id DESC)');
        $this->addSql('CREATE INDEX IDX_DD3446C5A76ED395 ON repost (user_id)');
        $this->addSql('ALTER TABLE repost ADD CONSTRAINT FK_DD3446C5A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE repost ADD CONSTRAINT FK_DD3446C54B89032C FOREIGN KEY (post_id) REFERENCES post (id) ON DELETE CASCADE NOT DEFERRABLE');

        // post.repost_count: new counter, folded into the existing chk_post_counters CHECK.
        $this->addSql('ALTER TABLE post ADD repost_count INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE post DROP CONSTRAINT chk_post_counters');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT chk_post_counters CHECK (like_count >= 0 AND comment_count >= 0 AND view_count >= 0 AND repost_count >= 0)');

        // notification: `repost` joins post_like/comment as a post-targeted notification.
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT chk_notification_target');
        $this->addSql(<<<'SQL'
            ALTER TABLE notification ADD CONSTRAINT chk_notification_target CHECK (
                (type = 'follow' AND track_id IS NULL AND playlist_id IS NULL AND post_id IS NULL)
                OR (type = 'track_like' AND track_id IS NOT NULL AND playlist_id IS NULL AND post_id IS NULL)
                OR (type = 'playlist_like' AND playlist_id IS NOT NULL AND track_id IS NULL AND post_id IS NULL)
                OR (type IN ('post_like', 'comment', 'repost') AND post_id IS NOT NULL AND track_id IS NULL AND playlist_id IS NULL)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE notification DROP CONSTRAINT chk_notification_target');
        $this->addSql(<<<'SQL'
            ALTER TABLE notification ADD CONSTRAINT chk_notification_target CHECK (
                (type = 'follow' AND track_id IS NULL AND playlist_id IS NULL AND post_id IS NULL)
                OR (type = 'track_like' AND track_id IS NOT NULL AND playlist_id IS NULL AND post_id IS NULL)
                OR (type = 'playlist_like' AND playlist_id IS NOT NULL AND track_id IS NULL AND post_id IS NULL)
                OR (type IN ('post_like', 'comment') AND post_id IS NOT NULL AND track_id IS NULL AND playlist_id IS NULL)
            )
            SQL);

        $this->addSql('ALTER TABLE post DROP CONSTRAINT chk_post_counters');
        $this->addSql('ALTER TABLE post ADD CONSTRAINT chk_post_counters CHECK (like_count >= 0 AND comment_count >= 0 AND view_count >= 0)');
        $this->addSql('ALTER TABLE post DROP repost_count');

        $this->addSql('ALTER TABLE repost DROP CONSTRAINT FK_DD3446C5A76ED395');
        $this->addSql('ALTER TABLE repost DROP CONSTRAINT FK_DD3446C54B89032C');
        $this->addSql('DROP TABLE repost');
    }
}
