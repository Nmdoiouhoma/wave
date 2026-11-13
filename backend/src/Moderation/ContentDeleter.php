<?php

declare(strict_types=1);

namespace App\Moderation;

use App\Entity\Artist;
use App\Entity\Playlist;
use App\Entity\Track;
use App\Enum\ReportTargetType;
use App\Message\PurgeObjects;
use App\Post\PostCleanup;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\CommentRepository;
use App\Repository\PlaylistRepository;
use App\Repository\ReportRepository;
use App\Repository\TrackRepository;
use App\Storage\StorageArea;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Hard deletion of one content item with the owner's own rules (DATA_MODEL §0): counters fixed,
 * polymorphic reports deleted, stored objects purged asynchronously. Shared by the owner routes
 * (`DELETE /tracks|/posts|/comments|/playlists|/artists/...`) and the admin deletion (W-71).
 * Every method must run inside the caller's transaction.
 */
final readonly class ContentDeleter
{
    public function __construct(
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private PostCleanup $postCleanup,
        private PlaylistRepository $playlists,
        private CommentRepository $comments,
        private ReportRepository $reports,
        private TrackRepository $tracks,
    ) {
    }

    /** Playlists compacted, every post on the track (anyone's) cleaned, then the FK cascade. */
    public function deleteTrack(Track $track): void
    {
        $private = array_values(array_filter([$track->getRawKey(), $track->getAudioKey()]));
        $public = null === $track->getCoverKey() ? [] : [$track->getCoverKey()];
        // Playlists keep exact item_count and contiguous positions (DATA_MODEL §7);
        // like_count dies with the track, listens/likes go by cascade.
        $this->playlists->detachTrackEverywhere($track->getId());
        // DATA_MODEL §9: every post on the track (anyone's) goes with hashtag counts,
        // reports and stored objects cleaned, before the FK cascade.
        $this->postCleanup->beforeTrackDeletion($track->getId());
        $this->em->remove($track);
        $this->em->flush();
        foreach ([StorageArea::Private->value => $private, StorageArea::Public->value => $public] as $area => $keys) {
            foreach (array_chunk($keys, PurgeObjects::MAX_KEYS) as $chunk) {
                $this->bus->dispatch(new PurgeObjects($area, $chunk));
            }
        }
    }

    public function deletePost(Uuid $postId): void
    {
        $this->postCleanup->deletePost($postId);
    }

    /** Reports on the comment deleted, `comment_count` decremented if it was visible. */
    public function deleteComment(Uuid $commentId): void
    {
        $this->comments->delete($commentId);
    }

    /** Items and likes go by FK cascade; reports (polymorphic, no FK) here. */
    public function deletePlaylist(Playlist $playlist): void
    {
        $this->reports->deleteForTarget(ReportTargetType::Playlist, $playlist->getId());
        $this->em->remove($playlist);
        $this->em->flush();
    }

    /** @throws ApiProblemException 409 `artist_has_tracks` while the artist has any track, whatever its status */
    public function deleteArtist(Artist $artist): void
    {
        // FK RESTRICT is the backstop.
        if ($this->tracks->countByArtist($artist) > 0) {
            throw new ApiProblemException(ProblemCode::ArtistHasTracks, 'Delete or move the tracks of this artist first.');
        }
        $avatar = $artist->getAvatarKey();
        // Reports are polymorphic (no FK): removed with the artist, never left in the queue.
        $this->reports->deleteForTarget(ReportTargetType::Artist, $artist->getId());
        $this->em->remove($artist);
        $this->em->flush();
        if (null !== $avatar) {
            $this->bus->dispatch(new PurgeObjects(StorageArea::Public->value, [$avatar]));
        }
    }
}
