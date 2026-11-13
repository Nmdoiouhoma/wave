<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-40 (DATA_MODEL §12, ADR 005): `user_affinity`, rewritten per user by RecomputeAffinity. */
final class Version20261113000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-40: user_affinity table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE user_affinity (kind VARCHAR(16) NOT NULL, "key" VARCHAR(64) NOT NULL, score DOUBLE PRECISION NOT NULL, updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (user_id, kind, "key"))');
        $this->addSql('CREATE INDEX idx_user_affinity_score ON user_affinity (user_id, kind, score DESC)');
        $this->addSql('ALTER TABLE user_affinity ADD CONSTRAINT FK_USER_AFFINITY_USER FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_affinity');
    }
}
