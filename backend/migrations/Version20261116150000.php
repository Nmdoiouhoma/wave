<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W-41 — playlist search: trigram index on the normalised name, like the ones `artist`, `track`
 * and `app_user` have since W-04 (typo-tolerant match, `daft pnk` → `Daft Punk`).
 * Hand-written: one CREATE INDEX, no DROP proposed by `migrations:diff` taken (CLAUDE.md).
 */
final class Version20261116150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-41: trigram index on playlist name for search';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_playlist_name_trgm ON playlist USING GIN (wave_unaccent(lower(name)) gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_playlist_name_trgm');
    }
}
