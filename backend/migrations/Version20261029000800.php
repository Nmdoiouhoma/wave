<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** W-20: playlists (DATA_MODEL §7). */
final class Version20261029000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-20: playlist, playlist_item';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE playlist (
                id UUID NOT NULL,
                owner_id UUID NOT NULL,
                name VARCHAR(100) NOT NULL,
                description VARCHAR(300) DEFAULT NULL,
                is_public BOOLEAN DEFAULT true NOT NULL,
                moderation VARCHAR(16) DEFAULT 'visible' NOT NULL,
                item_count INT DEFAULT 0 NOT NULL,
                like_count INT DEFAULT 0 NOT NULL,
                search_vector TSVECTOR DEFAULT '' NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_playlist_name CHECK (char_length(name) >= 1),
                CONSTRAINT chk_playlist_moderation CHECK (moderation IN ('visible', 'hidden')),
                CONSTRAINT chk_playlist_item_count CHECK (item_count >= 0 AND item_count <= 500),
                CONSTRAINT chk_playlist_like_count CHECK (like_count >= 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_playlist_owner_created ON playlist (owner_id, created_at DESC, id DESC)');
        $this->addSql('CREATE INDEX IDX_D782112D7E3C61F9 ON playlist (owner_id)');
        $this->addSql('CREATE INDEX idx_playlist_search ON playlist USING GIN (search_vector)');
        $this->addSql('ALTER TABLE playlist ADD CONSTRAINT FK_D782112D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql(<<<'SQL'
            CREATE FUNCTION wave_playlist_search_vector() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector('simple', wave_unaccent(lower(NEW.name))), 'A')
                    || setweight(to_tsvector('simple', wave_unaccent(lower(coalesce(NEW.description, '')))), 'B');
                RETURN NEW;
            END $$
            SQL);
        $this->addSql('CREATE TRIGGER trg_playlist_search_vector BEFORE INSERT OR UPDATE OF name, description ON playlist FOR EACH ROW EXECUTE FUNCTION wave_playlist_search_vector()');

        $this->addSql(<<<'SQL'
            CREATE TABLE playlist_item (
                id UUID NOT NULL,
                playlist_id UUID NOT NULL,
                track_id UUID NOT NULL,
                position INT NOT NULL,
                added_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT chk_playlist_item_position CHECK (position >= 0)
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_playlist_item_track ON playlist_item (playlist_id, track_id)');
        // Reorder rewrites every position in one transaction: uniqueness checked at commit.
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT uniq_playlist_item_position UNIQUE (playlist_id, position) DEFERRABLE INITIALLY DEFERRED');
        $this->addSql('CREATE INDEX IDX_BF02127C6BBD148 ON playlist_item (playlist_id)');
        $this->addSql('CREATE INDEX IDX_BF02127C5ED23C43 ON playlist_item (track_id)');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C6BBD148 FOREIGN KEY (playlist_id) REFERENCES playlist (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE playlist_item ADD CONSTRAINT FK_BF02127C5ED23C43 FOREIGN KEY (track_id) REFERENCES track (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE playlist_item');
        $this->addSql('DROP TABLE playlist');
        $this->addSql('DROP FUNCTION wave_playlist_search_vector()');
    }
}
