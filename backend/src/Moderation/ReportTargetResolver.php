<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\Artist;
use App\Entity\Comment;
use App\Entity\Playlist;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\ReportTargetType;
use App\Problem\ApiProblemException;
use App\Security\Voter\ArtistVoter;
use App\State\Provider\CommentLookup;
use App\State\Provider\PlaylistLookup;
use App\State\Provider\PostLookup;
use App\State\Provider\TrackLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The six report targets (DATA_MODEL §13). visibleTo(): the visibility rule of each type, a
 * target the caller cannot see is the same 404 as an unknown id. find(): any existing target,
 * hidden or suspended included (moderators).
 */
final readonly class ReportTargetResolver
{
    /** @var array<string, class-string> */
    public const array CLASSES = [
        'track' => Track::class,
        'post' => Post::class,
        'comment' => Comment::class,
        'playlist' => Playlist::class,
        'artist' => Artist::class,
        'user' => User::class,
    ];

    public function __construct(
        private TrackLookup $tracks,
        private PostLookup $posts,
        private CommentLookup $comments,
        private PlaylistLookup $playlists,
        private EntityManagerInterface $em,
        private AuthorizationCheckerInterface $auth,
    ) {
    }

    public function visibleTo(ReportTargetType $type, Uuid $id, User $viewer): Track|Post|Comment|Playlist|Artist|User
    {
        $raw = $id->toRfc4122();

        return match ($type) {
            ReportTargetType::Track => $this->tracks->visible($raw),
            ReportTargetType::Post => $this->posts->visible($raw, $viewer),
            ReportTargetType::Comment => $this->comments->visible($raw, $viewer),
            ReportTargetType::Playlist => $this->playlists->visible($raw),
            ReportTargetType::Artist => $this->visibleArtist($id),
            ReportTargetType::User => $this->activeUser($id),
        };
    }

    /** "Own content": track/artist/playlist owner, post/comment author, user = self. */
    public static function isOwn(Track|Post|Comment|Playlist|Artist|User $target, User $viewer): bool
    {
        $owner = match (true) {
            $target instanceof Track, $target instanceof Playlist, $target instanceof Artist => $target->getOwner(),
            $target instanceof Post, $target instanceof Comment => $target->getAuthor(),
            $target instanceof User => $target,
        };

        return $owner->getId()->equals($viewer->getId());
    }

    /**
     * `/admin/report-targets/{targetType}/{targetId}`: an unknown type or a malformed id is the
     * same 404 as an unknown target.
     *
     * @return array{ReportTargetType, Uuid}
     */
    public static function fromPath(mixed $type, mixed $id): array
    {
        $type = \is_string($type) ? ReportTargetType::tryFrom($type) : null;
        if (null === $type || !\is_string($id) || !Uuid::isValid($id)) {
            throw ApiProblemException::notFound();
        }

        return [$type, Uuid::fromString($id)];
    }

    public function find(ReportTargetType $type, Uuid $id): Track|Post|Comment|Playlist|Artist|User|null
    {
        /** @var Track|Post|Comment|Playlist|Artist|User|null $target */
        $target = $this->em->find(self::CLASSES[$type->value], $id);

        return $target;
    }

    private function visibleArtist(Uuid $id): Artist
    {
        $artist = $this->em->find(Artist::class, $id);

        return null !== $artist && $this->auth->isGranted(ArtistVoter::VIEW, $artist) ? $artist : throw ApiProblemException::notFound();
    }

    private function activeUser(Uuid $id): User
    {
        $user = $this->em->find(User::class, $id);

        return null !== $user && !$user->isSuspended() ? $user : throw ApiProblemException::notFound();
    }
}
