<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W-47 (FB-18, ADR 012): track audiences. `track.visibility` takes 4 values and `close_friend`
 * holds one close-friends list per user (DATA_MODEL §8). Members are followers, enforced by the
 * composite FK to `follow` (ON DELETE CASCADE: an unfollow removes the membership).
 * Hand-written: CHECKs, the composite FK. The Doctrine FK indexes on owner_id / friend_id are
 * left out on purpose (covered by the PK and by idx_close_friend_friend_owner).
 * Version20261110000100 (W-30) is applied in dev and is never edited.
 */
final class Version20261110000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-47: track audiences (4-value visibility) and close_friend';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE track DROP CONSTRAINT chk_track_visibility');
        $this->addSql("ALTER TABLE track ADD CONSTRAINT chk_track_visibility CHECK (visibility IN ('public', 'followers', 'close_friends', 'private'))");

        $this->addSql(<<<'SQL'
            CREATE TABLE close_friend (
                owner_id UUID NOT NULL,
                friend_id UUID NOT NULL,
                created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL,
                PRIMARY KEY (owner_id, friend_id),
                CONSTRAINT chk_close_friend_not_self CHECK (owner_id <> friend_id)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_close_friend_friend_owner ON close_friend (friend_id, owner_id)');
        $this->addSql('ALTER TABLE close_friend ADD CONSTRAINT FK_3EAFE6C67E3C61F9 FOREIGN KEY (owner_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE close_friend ADD CONSTRAINT FK_3EAFE6C66A5458E8 FOREIGN KEY (friend_id) REFERENCES app_user (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE close_friend ADD CONSTRAINT fk_close_friend_follow FOREIGN KEY (friend_id, owner_id) REFERENCES follow (follower_id, followed_id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    /** Restricted audiences fall back to `private` (owner only: never wider than before). */
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE close_friend');
        $this->addSql("UPDATE track SET visibility = 'private' WHERE visibility IN ('followers', 'close_friends')");
        $this->addSql('ALTER TABLE track DROP CONSTRAINT chk_track_visibility');
        $this->addSql("ALTER TABLE track ADD CONSTRAINT chk_track_visibility CHECK (visibility IN ('public', 'private'))");
    }
}
