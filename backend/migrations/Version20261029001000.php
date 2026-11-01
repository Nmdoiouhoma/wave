<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-22: track and playlist likes (DATA_MODEL §8). */
final class Version20261029001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-22: track_like, playlist_like';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE track_like (user_id UUID NOT NULL, track_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id, track_id))');
        $this->addSql('CREATE INDEX idx_track_like_track ON track_like (track_id)');
        $this->addSql('CREATE INDEX idx_track_like_user_created ON track_like (user_id, created_at DESC, track_id DESC)');
        $this->addSql('CREATE INDEX IDX_313568B6A76ED395 ON track_like (user_id)');
        $this->addSql('ALTER TABLE track_like ADD CONSTRAINT FK_313568B6A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE track_like ADD CONSTRAINT FK_313568B65ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');

        $this->addSql('CREATE TABLE playlist_like (user_id UUID NOT NULL, playlist_id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, PRIMARY KEY (user_id, playlist_id))');
        $this->addSql('CREATE INDEX idx_playlist_like_playlist ON playlist_like (playlist_id)');
        $this->addSql('CREATE INDEX idx_playlist_like_user_created ON playlist_like (user_id, created_at DESC, playlist_id DESC)');
        $this->addSql('CREATE INDEX IDX_C7A77D1A76ED395 ON playlist_like (user_id)');
        $this->addSql('ALTER TABLE playlist_like ADD CONSTRAINT FK_C7A77D1A76ED395 FOREIGN KEY (user_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE playlist_like ADD CONSTRAINT FK_C7A77D16BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE playlist_like');
        $this->addSql('DROP TABLE track_like');
    }
}
