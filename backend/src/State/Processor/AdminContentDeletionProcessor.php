<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\Dto\Input\AdminNoteInput;
use App\Entity\Artist;
use App\Entity\Comment;
use App\Entity\Playlist;
use App\Entity\Post;
use App\Entity\Track;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Moderation\ContentDeleter;
use App\Moderation\ReportTargetResolver;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /admin/content/{contentType}/{contentId}/deletion` (ROLE_ADMIN, 204, FB-28). Irreversible
 * hard delete with the owner's own rules ({@see ContentDeleter}). Order: body (422) → unknown
 * type or id (404) → artist with tracks (409 `artist_has_tracks`). `admin.content_deleted`
 * (subject = owner, target = the item, `contentType` and `note`) in the deletion's transaction:
 * a failed deletion leaves no row. The owner is not notified in v1.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class AdminContentDeletionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private ReportTargetResolver $targets,
        private ContentDeleter $deleter,
        private EntityManagerInterface $em,
        private ActivityJournal $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $admin = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $note = trim((string) $this->decoder->decode($request, AdminNoteInput::class)->note);
        [$type, $id] = ReportTargetResolver::fromPath($uriVariables['contentType'] ?? null, $uriVariables['contentId'] ?? null);
        if (ReportTargetType::User === $type) {
            throw ApiProblemException::notFound();
        }
        $target = $this->targets->find($type, $id) ?? throw ApiProblemException::notFound();
        \assert(!$target instanceof \App\Entity\User);
        $ownerId = ReportTargetResolver::ownerOf($target)->getId();

        $this->em->wrapInTransaction(function () use ($target, $type, $id, $ownerId, $admin, $note): void {
            match (true) {
                $target instanceof Track => $this->deleter->deleteTrack($target),
                $target instanceof Post => $this->deleter->deletePost($id),
                $target instanceof Comment => $this->deleter->deleteComment($id),
                $target instanceof Playlist => $this->deleter->deletePlaylist($target),
                $target instanceof Artist => $this->deleter->deleteArtist($target),
            };
            $this->journal->record(ActivityType::ContentDeleted, $admin, $ownerId, $type, $id, ['contentType' => $type->value, 'note' => $note]);
        });
        if ($target instanceof Post || $target instanceof Comment) {
            $this->em->detach($target);
        }

        return null;
    }
}
