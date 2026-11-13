<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W-70 (FB-28, ADR 013): activity journal, security alerts, announcements, app_user.last_login_at
 * (DATA_MODEL §2, §15, §16, §17).
 * Hand-written, never to be reproduced by a diff: the CHECKs, the DESC and partial indexes of
 * activity_event, the generated column security_alert.subject_key and its partial unique index,
 * idx_security_alert_status. The Doctrine FK indexes on activity_event.actor_id / subject_user_id
 * are replaced by the indexes of §15.
 */
final class Version20261113000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-70: activity_event, security_alert, announcement, app_user.last_login_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD last_login_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');

        $this->addSql(<<<'SQL'
            CREATE TABLE activity_event (
                id UUID NOT NULL,
                category VARCHAR(16) NOT NULL,
                type VARCHAR(40) NOT NULL,
                actor_id UUID DEFAULT NULL,
                subject_user_id UUID DEFAULT NULL,
                target_type VARCHAR(16) DEFAULT NULL,
                target_id UUID DEFAULT NULL,
                ip_prefix VARCHAR(43) DEFAULT NULL,
                ip_hash BYTEA DEFAULT NULL,
                user_agent VARCHAR(200) DEFAULT NULL,
                metadata JSONB DEFAULT '{}' NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_activity_event_category CHECK (category IN ('auth', 'account', 'content', 'report', 'upload', 'abuse', 'admin')),
                CONSTRAINT chk_activity_event_type CHECK (type IN (
                    'auth.login_succeeded', 'auth.login_failed', 'auth.logout', 'auth.token_reused', 'auth.password_changed',
                    'auth.password_reset_requested', 'auth.password_reset_completed',
                    'account.registered', 'account.email_verified', 'account.email_changed',
                    'content.track_created', 'content.track_deleted', 'content.post_created', 'content.post_deleted',
                    'content.comment_created', 'content.artist_created',
                    'report.created', 'report.auto_hidden',
                    'upload.rejected',
                    'abuse.rate_limited',
                    'admin.user_suspended', 'admin.user_unsuspended', 'admin.role_changed', 'admin.content_hidden',
                    'admin.content_restored', 'admin.content_deleted', 'admin.report_decided', 'admin.alert_acknowledged',
                    'admin.announcement_created', 'admin.announcement_updated', 'admin.announcement_deleted',
                    'admin.journal_viewed', 'admin.admin_granted', 'admin.admin_revoked'
                )),
                CONSTRAINT chk_activity_event_type_category CHECK (split_part(type, '.', 1) = category),
                CONSTRAINT chk_activity_event_target_type CHECK (target_type IS NULL OR target_type IN ('track', 'post', 'comment', 'playlist', 'artist', 'user', 'alert', 'announcement')),
                CONSTRAINT chk_activity_event_ip_hash CHECK (ip_hash IS NULL OR octet_length(ip_hash) = 16),
                CONSTRAINT chk_activity_event_metadata CHECK (jsonb_typeof(metadata) = 'object')
            )
            SQL);
        $this->addSql('CREATE INDEX idx_activity_event_subject ON activity_event (subject_user_id, id DESC)');
        $this->addSql("CREATE INDEX idx_activity_event_admin_actor ON activity_event (actor_id, id DESC) WHERE category = 'admin'");
        $this->addSql('CREATE INDEX idx_activity_event_type_created ON activity_event (category, type, created_at)');
        $this->addSql('CREATE INDEX idx_activity_event_ip_hash ON activity_event (ip_hash, type, created_at) WHERE ip_hash IS NOT NULL');
        $this->addSql('CREATE INDEX idx_activity_event_created ON activity_event (created_at)');
        $this->addSql('ALTER TABLE activity_event ADD CONSTRAINT FK_25AC3C1410DAF24A FOREIGN KEY (actor_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE activity_event ADD CONSTRAINT FK_25AC3C142EC7F37 FOREIGN KEY (subject_user_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE security_alert (
                id UUID NOT NULL,
                rule VARCHAR(32) NOT NULL,
                severity VARCHAR(16) NOT NULL,
                subject_user_id UUID DEFAULT NULL,
                subject_ip_hash BYTEA DEFAULT NULL,
                subject_target_type VARCHAR(16) DEFAULT NULL,
                subject_target_id UUID DEFAULT NULL,
                hit_count INT DEFAULT 1 NOT NULL,
                first_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                last_seen_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                status VARCHAR(16) DEFAULT 'open' NOT NULL,
                acknowledged_by_id UUID DEFAULT NULL,
                acknowledged_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                note VARCHAR(500) DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                subject_key TEXT GENERATED ALWAYS AS (
                    rule || ':' || coalesce(subject_user_id::text, encode(subject_ip_hash, 'hex'), subject_target_type || ':' || subject_target_id::text)
                ) STORED,
                PRIMARY KEY (id),
                CONSTRAINT chk_security_alert_rule CHECK (rule IN ('login_failures', 'token_reuse', 'mass_registration', 'report_burst', 'report_spree', 'upload_abuse', 'rate_limit_hammering', 'privilege_granted')),
                CONSTRAINT chk_security_alert_severity CHECK (severity IN ('low', 'medium', 'high')),
                CONSTRAINT chk_security_alert_status CHECK (status IN ('open', 'acknowledged')),
                CONSTRAINT chk_security_alert_hit_count CHECK (hit_count >= 1),
                CONSTRAINT chk_security_alert_subject CHECK (subject_user_id IS NOT NULL OR subject_ip_hash IS NOT NULL OR (subject_target_type IS NOT NULL AND subject_target_id IS NOT NULL))
            )
            SQL);
        $this->addSql("CREATE UNIQUE INDEX uniq_security_alert_open_subject ON security_alert (subject_key) WHERE status = 'open'");
        $this->addSql('CREATE INDEX idx_security_alert_status ON security_alert (status, severity, last_seen_at DESC)');
        $this->addSql('CREATE INDEX idx_security_alert_subject_user ON security_alert (subject_user_id)');
        $this->addSql('CREATE INDEX IDX_FB41A56B4DEFF5F ON security_alert (acknowledged_by_id)');
        $this->addSql('ALTER TABLE security_alert ADD CONSTRAINT FK_FB41A56B2EC7F37 FOREIGN KEY (subject_user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE security_alert ADD CONSTRAINT FK_FB41A56B4DEFF5F FOREIGN KEY (acknowledged_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql(<<<'SQL'
            CREATE TABLE announcement (
                id UUID NOT NULL,
                title VARCHAR(80) NOT NULL,
                body VARCHAR(500) NOT NULL,
                level VARCHAR(16) NOT NULL,
                starts_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                ends_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_by_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_announcement_level CHECK (level IN ('info', 'warning')),
                CONSTRAINT chk_announcement_window CHECK (ends_at IS NULL OR ends_at > starts_at)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_announcement_window ON announcement (starts_at, ends_at)');
        $this->addSql('CREATE INDEX IDX_4DB9D91CB03A8386 ON announcement (created_by_id)');
        $this->addSql('ALTER TABLE announcement ADD CONSTRAINT FK_4DB9D91CB03A8386 FOREIGN KEY (created_by_id) REFERENCES app_user (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE announcement');
        $this->addSql('DROP TABLE security_alert');
        $this->addSql('DROP TABLE activity_event');
        $this->addSql('ALTER TABLE app_user DROP last_login_at');
    }
}
