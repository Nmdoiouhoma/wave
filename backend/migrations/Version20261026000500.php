<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-60 (ADR 009): direct-to-storage upload slots; track.size_bytes up to 200 MB (MP4). */
final class Version20261026000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-60: track_upload, track.size_bytes <= 200 MB';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE track_upload (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                quarantine_key VARCHAR(255) NOT NULL,
                declared_filename VARCHAR(255) NOT NULL,
                declared_size INT NOT NULL,
                declared_type VARCHAR(32) NOT NULL,
                max_bytes INT NOT NULL,
                status VARCHAR(16) DEFAULT 'pending' NOT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                consumed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                track_id UUID DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_track_upload_status CHECK (status IN ('pending', 'consumed', 'expired')),
                CONSTRAINT chk_track_upload_sizes CHECK (declared_size > 0 AND max_bytes > 0 AND max_bytes <= 209715200)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_track_upload_key ON track_upload (quarantine_key)');
        $this->addSql('CREATE INDEX idx_track_upload_user_status ON track_upload (user_id, status)');
        $this->addSql('CREATE INDEX idx_track_upload_status_created ON track_upload (status, created_at)');
        $this->addSql('CREATE INDEX IDX_5F7F27F6A76ED395 ON track_upload (user_id)');
        $this->addSql('CREATE INDEX IDX_5F7F27F65ED23C43 ON track_upload (track_id)');
        $this->addSql('ALTER TABLE track_upload ADD CONSTRAINT FK_5F7F27F6A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE track_upload ADD CONSTRAINT FK_5F7F27F65ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE SET NULL NOT DEFERRABLE');

        $this->addSql('ALTER TABLE track DROP CONSTRAINT chk_track_size');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT chk_track_size CHECK (size_bytes >= 0 AND size_bytes <= 209715200)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE track_upload');
        $this->addSql('ALTER TABLE track DROP CONSTRAINT chk_track_size');
        $this->addSql('ALTER TABLE track ADD CONSTRAINT chk_track_size CHECK (size_bytes >= 0 AND size_bytes <= 31457280)');
    }
}
