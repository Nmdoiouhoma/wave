<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-21: follow graph (DATA_MODEL §8). */
final class Version20261029000900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-21: follow';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE follow (
                follower_id UUID NOT NULL,
                followed_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (follower_id, followed_id),
                CONSTRAINT chk_follow_not_self CHECK (follower_id <> followed_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_follow_followed_created ON follow (followed_id, created_at DESC, follower_id DESC)');
        $this->addSql('CREATE INDEX idx_follow_follower_created ON follow (follower_id, created_at DESC, followed_id DESC)');
        $this->addSql('CREATE INDEX IDX_68344470AC24F853 ON follow (follower_id)');
        $this->addSql('CREATE INDEX IDX_68344470D956F010 ON follow (followed_id)');
        $this->addSql('ALTER TABLE follow ADD CONSTRAINT FK_68344470AC24F853 FOREIGN KEY (follower_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE follow ADD CONSTRAINT FK_68344470D956F010 FOREIGN KEY (followed_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE follow');
    }
}
