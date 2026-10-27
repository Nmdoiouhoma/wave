<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** FB-10: failure reason `no_audio` (media without any audio stream). */
final class Version20261027000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FB-10: track.failure_reason accepts no_audio';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track DROP CONSTRAINT chk_track_failure_reason');
        $this->addSql("ALTER TABLE track ADD CONSTRAINT chk_track_failure_reason CHECK (failure_reason IS NULL OR failure_reason IN ('invalid_media', 'unsupported_codec', 'too_long', 'too_large', 'processing_error', 'no_audio'))");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE track SET failure_reason = 'invalid_media' WHERE failure_reason = 'no_audio'");
        $this->addSql('ALTER TABLE track DROP CONSTRAINT chk_track_failure_reason');
        $this->addSql("ALTER TABLE track ADD CONSTRAINT chk_track_failure_reason CHECK (failure_reason IS NULL OR failure_reason IN ('invalid_media', 'unsupported_codec', 'too_long', 'too_large', 'processing_error'))");
    }
}
