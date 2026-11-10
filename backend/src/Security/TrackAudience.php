<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Enum\Moderation;
use App\Enum\Visibility;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Uid\Uuid;

/**
 * The one audience rule of FB-18 (ADR 012, DATA_MODEL §6, §9). Every "track visible to the
 * caller" check goes through here: the SQL fragment for queries (native and DQL renderings of
 * the same predicate, uncorrelated `IN` → hashed SubPlans, bound `:v`) and the PHP check for
 * loaded tracks and posts (batched: one query for a whole page).
 *
 * Public-only surfaces (`artist.track_count`, trending index, discovery) keep
 * `Track::isPublicVisible()`: they are the same for every viewer.
 */
final readonly class TrackAudience
{
    /** Bound as `:v` for an anonymous caller: follows nobody, owns nothing. */
    public const string NO_VIEWER = '00000000-0000-0000-0000-000000000000';

    public function __construct(private Connection $db)
    {
    }

    /** Native SQL: `:v` is in the audience of track `$t` (ADR 012 §8). */
    public static function sql(string $t = 't'): string
    {
        return "({$t}.visibility = 'public' OR {$t}.owner_id = :v"
            ." OR ({$t}.visibility = 'followers' AND {$t}.owner_id IN (SELECT followed_id FROM follow WHERE follower_id = :v))"
            ." OR ({$t}.visibility = 'close_friends' AND {$t}.owner_id IN (SELECT owner_id FROM close_friend WHERE friend_id = :v)))";
    }

    /** Native SQL: track `$t` visible to `:v` (§6); binds `:v` and `:mod` (see params()). */
    public static function visibleSql(string $t = 't'): string
    {
        return "(:mod = 1 OR {$t}.owner_id = :v OR ({$t}.status = 'ready' AND {$t}.moderation = 'visible' AND ".self::sql($t).'))';
    }

    /** DQL rendering of sql() (same predicate); bind `:v` with bindDql(). */
    public static function dql(string $t = 't'): string
    {
        return "({$t}.visibility = 'public' OR {$t}.owner = :v"
            ." OR ({$t}.visibility = 'followers' AND {$t}.owner IN (SELECT IDENTITY(aud_f.followed) FROM App\\Entity\\Follow aud_f WHERE aud_f.follower = :v))"
            ." OR ({$t}.visibility = 'close_friends' AND {$t}.owner IN (SELECT IDENTITY(aud_cf.owner) FROM App\\Entity\\CloseFriend aud_cf WHERE aud_cf.friend = :v)))";
    }

    /** DQL: track `$t` visible to `:v`, moderators excepted (callers branch on them). */
    public static function visibleDql(string $t = 't'): string
    {
        return "({$t}.owner = :v OR ({$t}.status = 'ready' AND {$t}.moderation = 'visible' AND ".self::dql($t).'))';
    }

    /**
     * Native SQL: post `$p` (track `$t`, author `$u`) is playable for `:v` (DATA_MODEL §9,
     * viewer-dependent, FB-18). Same rule as isPostPlayable(); binds `:v` only.
     */
    public static function postPlayableSql(string $p = 'p', string $t = 't', string $u = 'u'): string
    {
        return "({$p}.status = 'ready' AND {$p}.moderation = 'visible' AND {$t}.moderation = 'visible' AND {$u}.suspended_at IS NULL"
            ." AND (({$t}.visibility = 'public' AND {$t}.allow_clips = true) OR ({$t}.owner_id = {$p}.author_id AND ".self::sql($t).')))';
    }

    /** DQL rendering of postPlayableSql() (same predicate); bind `:v` with bindDql(). */
    public static function postPlayableDql(string $p = 'p', string $t = 't', string $u = 'u'): string
    {
        return "({$p}.status = 'ready' AND {$p}.moderation = 'visible' AND {$t}.moderation = 'visible' AND {$u}.suspendedAt IS NULL"
            ." AND (({$t}.visibility = 'public' AND {$t}.allowClips = true) OR ({$t}.owner = {$p}.author AND ".self::dql($t).')))';
    }

    public static function bindDql(QueryBuilder $qb, ?User $viewer): void
    {
        $qb->setParameter('v', $viewer?->getId() ?? Uuid::fromString(self::NO_VIEWER), 'uuid');
    }

    /** @return array{v: string, mod: int} */
    public static function params(?User $viewer): array
    {
        return ['v' => $viewer?->getId()->toRfc4122() ?? self::NO_VIEWER, 'mod' => null !== $viewer && $viewer->isModerator() ? 1 : 0];
    }

    /** DATA_MODEL §6 for one loaded track. $asModerator = false: moderators count as anyone. */
    public function isTrackVisible(Track $track, ?User $viewer, bool $asModerator = true): bool
    {
        return $this->trackVisibility([$track], $viewer, $asModerator)[$track->getId()->toRfc4122()];
    }

    /**
     * @param list<Track> $tracks
     *
     * @return array<string, bool> track id => visible
     */
    public function trackVisibility(array $tracks, ?User $viewer, bool $asModerator = true): array
    {
        $members = $this->memberships($viewer, $tracks);
        $out = [];
        foreach ($tracks as $track) {
            $out[$track->getId()->toRfc4122()] = $this->visible($track, $viewer, $members, $asModerator);
        }

        return $out;
    }

    public function isPostPlayable(Post $post, ?User $viewer): bool
    {
        return $this->playable($post, $viewer, $this->memberships($viewer, [$post->getTrack()]));
    }

    public function isPostVisible(Post $post, ?User $viewer): bool
    {
        return $this->postVisibility([$post], $viewer)[$post->getId()->toRfc4122()];
    }

    /**
     * DATA_MODEL §9 "visible to a caller": moderator, playable, or author who can still see the track.
     *
     * @param list<Post> $posts
     *
     * @return array<string, bool> post id => visible
     */
    public function postVisibility(array $posts, ?User $viewer): array
    {
        $members = $this->memberships($viewer, array_map(static fn (Post $p): Track => $p->getTrack(), $posts));
        $out = [];
        foreach ($posts as $post) {
            $out[$post->getId()->toRfc4122()] = null !== $viewer && (
                $viewer->isModerator()
                || $this->playable($post, $viewer, $members)
                || ($post->isAuthoredBy($viewer) && $this->visible($post->getTrack(), $viewer, $members, true))
            );
        }

        return $out;
    }

    /** @param array<string, bool> $members owner id => is close friend (key present = follower) */
    private function visible(Track $track, ?User $viewer, array $members, bool $asModerator): bool
    {
        if ($track->isOwnedBy($viewer) || ($asModerator && null !== $viewer && $viewer->isModerator())) {
            return true;
        }

        return MediaStatus::Ready === $track->getStatus()
            && Moderation::Visible === $track->getModeration()
            && $this->inAudience($track, $viewer, $members);
    }

    /**
     * DATA_MODEL §9 "playable" for $viewer: ready, not hidden, author not suspended, and either a
     * public clippable track or the owner's own track with $viewer in its audience.
     *
     * @param array<string, bool> $members
     */
    private function playable(Post $post, ?User $viewer, array $members): bool
    {
        $track = $post->getTrack();

        return MediaStatus::Ready === $post->getStatus()
            && Moderation::Visible === $post->getModeration()
            && Moderation::Visible === $track->getModeration()
            && !$post->getAuthor()->isSuspended()
            && ((Visibility::Public === $track->getVisibility() && $track->isAllowClips())
                || ($track->isOwnedBy($post->getAuthor()) && $this->inAudience($track, $viewer, $members)));
    }

    /** @param array<string, bool> $members */
    private function inAudience(Track $track, ?User $viewer, array $members): bool
    {
        if (Visibility::Public === $track->getVisibility() || $track->isOwnedBy($viewer)) {
            return true;
        }
        $owner = $track->getOwner()->getId()->toRfc4122();

        return match ($track->getVisibility()) {
            Visibility::Followers => isset($members[$owner]),
            Visibility::CloseFriends => $members[$owner] ?? false,
            default => false,
        };
    }

    /**
     * One query: which owners of restricted tracks $viewer follows, and in whose close-friends
     * list they are (close friends ⊂ followers, enforced by the composite FK).
     *
     * @param list<Track> $tracks
     *
     * @return array<string, bool> owner id => is close friend
     */
    private function memberships(?User $viewer, array $tracks): array
    {
        if (null === $viewer) {
            return [];
        }
        $owners = [];
        foreach ($tracks as $track) {
            if (\in_array($track->getVisibility(), [Visibility::Followers, Visibility::CloseFriends], true) && !$track->isOwnedBy($viewer)) {
                $owners[$track->getOwner()->getId()->toRfc4122()] = true;
            }
        }
        if ([] === $owners) {
            return [];
        }
        $rows = $this->db->fetchAllKeyValue(
            'SELECT f.followed_id, (cf.owner_id IS NOT NULL) AS close FROM follow f
             LEFT JOIN close_friend cf ON cf.owner_id = f.followed_id AND cf.friend_id = f.follower_id
             WHERE f.follower_id = :v AND f.followed_id IN (:owners)',
            ['v' => $viewer->getId()->toRfc4122(), 'owners' => array_keys($owners)],
            ['owners' => ArrayParameterType::STRING],
        );
        $members = [];
        foreach ($rows as $owner => $close) {
            $members[(string) $owner] = true === $close || 't' === $close || '1' === (string) $close;
        }

        return $members;
    }
}
