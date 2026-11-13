<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * W-72 (ADR 013, DATA_MODEL §15): two partial indexes for the admin journal pages, written by
 * hand and never to be reproduced by a diff.
 * - `idx_activity_event_admin`: `GET /admin/audit-log` without `actorId` (newest admin events
 *   first) would otherwise walk the primary key backwards through every non-admin event.
 * - `idx_activity_event_actor_other`: the user journal lists events whose ACTOR is the user
 *   while the subject is someone else (reports filed, a post deleted by its author on another
 *   subject…); without it that branch scans the table.
 */
final class Version20261113000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'W-72: partial indexes of activity_event for the admin journal and audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE INDEX idx_activity_event_admin ON activity_event (id DESC) WHERE category = 'admin'");
        $this->addSql("CREATE INDEX idx_activity_event_actor_other ON activity_event (actor_id, id DESC) WHERE category <> 'admin' AND actor_id IS DISTINCT FROM subject_user_id");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_activity_event_actor_other');
        $this->addSql('DROP INDEX idx_activity_event_admin');
    }
}
