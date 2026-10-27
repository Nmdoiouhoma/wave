<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FB-08: genres for a French audience — new genres (rap, rap-fr, afro…), French display
 * names, and a new `position` order (rap/hip-hop/pop/afro/rnb first, `other` last).
 * Ids (slugs) of existing genres never change, so tracks keep their genre.
 */
final class Version20261027000600 extends AbstractMigration
{
    private const array NEW = [
        'rap' => 'Rap', 'rap-fr' => 'Rap français', 'trap' => 'Trap', 'drill' => 'Drill', 'afro' => 'Afro',
        'amapiano' => 'Amapiano', 'dancehall' => 'Dancehall', 'zouk' => 'Zouk', 'kompa' => 'Kompa',
        'coupe-decale' => 'Coupé-décalé', 'rai' => 'Raï', 'variete-fr' => 'Variété française', 'chanson' => 'Chanson',
        'gospel' => 'Gospel', 'k-pop' => 'K-pop', 'reggaeton' => 'Reggaeton', 'indie' => 'Indé', 'edm' => 'EDM',
        'instrumental' => 'Instrumental', 'beats' => 'Beats / Prods',
    ];

    /** Down: where tracks of a removed genre go back to (FK is RESTRICT). */
    private const array DOWN_FALLBACK = [
        'rap' => 'hip-hop', 'rap-fr' => 'hip-hop', 'trap' => 'hip-hop', 'drill' => 'hip-hop', 'afro' => 'afrobeats',
        'amapiano' => 'house', 'dancehall' => 'reggae', 'reggaeton' => 'latin', 'indie' => 'rock', 'edm' => 'electronic',
        'k-pop' => 'pop', 'variete-fr' => 'pop', 'chanson' => 'folk', 'gospel' => 'soul',
    ];

    private const array RENAMED = [
        'electronic' => ['Electronic', 'Électro'],
        'classical' => ['Classical', 'Classique'],
        'soundtrack' => ['Soundtrack', 'Musique de film'],
        'world' => ['World', 'Musiques du monde'],
        'other' => ['Other', 'Autre'],
        'latin' => ['Latin', 'Latino'],
    ];

    private const array ORDER = [
        'rap', 'rap-fr', 'hip-hop', 'pop', 'afro', 'rnb', 'afrobeats', 'trap', 'drill', 'amapiano', 'dancehall',
        'reggaeton', 'zouk', 'kompa', 'coupe-decale', 'rai', 'variete-fr', 'chanson', 'electronic', 'house', 'techno',
        'edm', 'drum-and-bass', 'lofi', 'beats', 'instrumental', 'rock', 'indie', 'metal', 'punk', 'jazz', 'blues',
        'soul', 'funk', 'gospel', 'reggae', 'latin', 'k-pop', 'classical', 'folk', 'country', 'ambient', 'soundtrack',
        'world', 'other',
    ];

    private const array OLD_ORDER = [
        'pop', 'rock', 'hip-hop', 'rnb', 'electronic', 'house', 'techno', 'drum-and-bass', 'jazz', 'blues', 'classical',
        'folk', 'country', 'metal', 'punk', 'reggae', 'latin', 'afrobeats', 'soul', 'funk', 'lofi', 'ambient',
        'soundtrack', 'world', 'other',
    ];

    public function getDescription(): string
    {
        return 'FB-08: French-audience genres, French names, new display order';
    }

    public function up(Schema $schema): void
    {
        foreach (self::NEW as $id => $name) {
            $this->addSql('INSERT INTO genre (id, name, position) VALUES (:id, :name, 0)', ['id' => $id, 'name' => $name]);
        }
        foreach (self::RENAMED as $id => [, $name]) {
            $this->addSql('UPDATE genre SET name = :name WHERE id = :id', ['id' => $id, 'name' => $name]);
        }
        foreach (self::ORDER as $position => $id) {
            $this->addSql('UPDATE genre SET position = :p WHERE id = :id', ['id' => $id, 'p' => $position]);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::NEW) as $id) {
            $this->addSql('UPDATE track SET genre_id = :to WHERE genre_id = :from', ['from' => $id, 'to' => self::DOWN_FALLBACK[$id] ?? 'other']);
            $this->addSql('DELETE FROM genre WHERE id = :id', ['id' => $id]);
        }
        foreach (self::RENAMED as $id => [$name]) {
            $this->addSql('UPDATE genre SET name = :name WHERE id = :id', ['id' => $id, 'name' => $name]);
        }
        foreach (self::OLD_ORDER as $position => $id) {
            $this->addSql('UPDATE genre SET position = :p WHERE id = :id', ['id' => $id, 'p' => $position]);
        }
    }
}
