<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\User;
use App\Enum\Moderation;
use App\Enum\ReportTargetType;
use App\Repository\ArtistRepository;
use App\Security\SessionIssuer;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Moderation writes (DATA_MODEL §10, §13): conditional UPDATEs (`… WHERE moderation <> :m`) that
 * move counters only when a row actually changed, so repeated hide/dismiss never drift. Call
 * inside a transaction holding the target's advisory lock.
 */
final readonly class ModerationApplier
{
    public function __construct(
        private Connection $db,
        private ArtistRepository $artists,
        private SessionIssuer $sessions,
    ) {
    }

    /** Sets the moderation of a content target; true when the row changed. */
    public function setModeration(ReportTargetType $type, Uuid $id, Moderation $moderation): bool
    {
        $params = ['m' => $moderation->value, 'id' => $id->toRfc4122()];
        switch ($type) {
            case ReportTargetType::Track:
                $artist = $this->db->fetchOne('UPDATE track SET moderation = :m WHERE id = :id AND moderation <> :m RETURNING artist_id', $params);
                if (false === $artist) {
                    return false;
                }
                $this->artists->recountTracks(Uuid::fromString((string) $artist));

                return true;
            case ReportTargetType::Comment:
                $post = $this->db->fetchOne('UPDATE comment SET moderation = :m WHERE id = :id AND moderation <> :m RETURNING post_id', $params);
                if (false === $post) {
                    return false;
                }
                $delta = Moderation::Hidden === $moderation ? '- 1' : '+ 1';
                $this->db->executeStatement("UPDATE post SET comment_count = comment_count $delta WHERE id = :p", ['p' => $post]);

                return true;
            case ReportTargetType::Post:
            case ReportTargetType::Playlist:
            case ReportTargetType::Artist:
                return 1 === (int) $this->db->executeStatement("UPDATE {$type->value} SET moderation = :m WHERE id = :id AND moderation <> :m", $params);
            case ReportTargetType::User:
                throw new \LogicException('User targets are suspended, not hidden.');
        }
    }

    /** Suspends the account and deletes every refresh token (the access JWT dies at its next request). */
    public function suspend(User $user): bool
    {
        $changed = 1 === (int) $this->db->executeStatement(
            'UPDATE app_user SET suspended_at = now() WHERE id = :id AND suspended_at IS NULL',
            ['id' => $user->getId()->toRfc4122()],
        );
        $this->sessions->revokeAll($user);

        return $changed;
    }
}
