<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-26 F13 (ADR 010): listen grants written by stream-url and consumed by listen reports (DATA_MODEL §11). */
final class Version20261107000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-26: listen_grant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE listen_grant (issued_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, user_id UUID NOT NULL, track_id UUID NOT NULL, PRIMARY KEY (user_id, track_id))');
        $this->addSql('CREATE INDEX IDX_7C58510DA76ED395 ON listen_grant (user_id)');
        $this->addSql('CREATE INDEX IDX_7C58510D5ED23C43 ON listen_grant (track_id)');
        $this->addSql('ALTER TABLE listen_grant ADD CONSTRAINT FK_7C58510DA76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE listen_grant ADD CONSTRAINT FK_7C58510D5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE listen_grant');
    }
}
