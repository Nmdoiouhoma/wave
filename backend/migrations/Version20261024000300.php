<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-17: account_token (DATA_MODEL §3b), shared by email verification and password reset. */
final class Version20261024000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-17: account_token';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE account_token (
                id UUID NOT NULL,
                user_id UUID NOT NULL,
                purpose VARCHAR(32) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                expires_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                used_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_account_token_purpose CHECK (purpose IN ('email_verification', 'password_reset'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_account_token_hash ON account_token (token_hash)');
        $this->addSql('CREATE INDEX idx_account_token_user_purpose ON account_token (user_id, purpose)');
        $this->addSql('CREATE INDEX IDX_69B8784AA76ED395 ON account_token (user_id)');
        $this->addSql('ALTER TABLE account_token ADD CONSTRAINT FK_69B8784AA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE account_token');
    }
}
