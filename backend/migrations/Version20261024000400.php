<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * ADR 008 (security finding F2): refresh-token families. Rotated tokens are kept with
 * `rotated_at` until expiry so that replaying one revokes every token of its family.
 */
final class Version20261024000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR 008: refresh_token.family_id and rotated_at (reuse detection)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE refresh_token ADD family_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE refresh_token ADD rotated_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        // Existing sessions: each token becomes its own family.
        $this->addSql('UPDATE refresh_token SET family_id = gen_random_uuid()');
        $this->addSql('ALTER TABLE refresh_token ALTER family_id SET NOT NULL');
        $this->addSql('CREATE INDEX idx_refresh_token_family_id ON refresh_token (family_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_refresh_token_family_id');
        $this->addSql('ALTER TABLE refresh_token DROP rotated_at');
        $this->addSql('ALTER TABLE refresh_token DROP family_id');
    }
}
