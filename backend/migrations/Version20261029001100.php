<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-23: counted listens (DATA_MODEL §11; `post_id` FK → post comes with W-30) and the lock table of message deduplication. */
final class Version20261029001100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-23: listen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
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
            )
            SQL);
        $this->addSql('CREATE INDEX idx_listen_user_created ON listen (user_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_listen_track_created ON listen (track_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_listen_user_track_created ON listen (user_id, track_id, created_at DESC)');
        $this->addSql('CREATE INDEX IDX_C22467FDA76ED395 ON listen (user_id)');
        $this->addSql('CREATE INDEX IDX_C22467FD5ED23C43 ON listen (track_id)');
        $this->addSql('ALTER TABLE listen ADD CONSTRAINT FK_C22467FDA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listen ADD CONSTRAINT FK_C22467FD5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');

        // Symfony Lock DoctrineDbalStore, used by DeduplicateStamp (RecomputeAffinity).
        $this->addSql('CREATE TABLE lock_keys (key_id VARCHAR(64) NOT NULL, key_token VARCHAR(44) NOT NULL, key_expiration INT NOT NULL, PRIMARY KEY (key_id))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE lock_keys');
        $this->addSql('DROP TABLE listen');
    }
}
