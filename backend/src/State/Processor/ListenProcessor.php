<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ListenResultView;
use App\Dto\Input\ListenInput;
use App\Entity\User;
use App\Enum\ListenSource;
use App\Enum\MediaStatus;
use App\Http\JsonInputDecoder;
use App\Listen\ListenRecorder;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\PostRepository;
use App\Security\CurrentUser;
use App\Security\TrackAudience;
use App\State\Provider\TrackLookup;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /tracks/{trackId}/listens` (200 counted or not). `source=player`: track visible to the
 * caller (404), `postId` forbidden (422). `source=feed` (W-32, DATA_MODEL §11): `postId` must be a
 * post of this track playable for the caller (TrackAudience, viewer-dependent), checked instead of
 * the track visibility; anything else — missing, unknown, other track, not playable — is the same
 * 422. The reference duration is then the clip's.
 *
 * @implements ProcessorInterface<mixed, ListenResultView>
 */
final readonly class ListenProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private TrackLookup $tracks,
        private ListenRecorder $recorder,
        private PostRepository $posts,
        private TrackAudience $audience,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ListenResultView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, ListenInput::class);

        if (ListenSource::Feed === $input->source) {
            return $this->feedListen($user, $uriVariables['trackId'] ?? null, $input);
        }
        if (null !== $input->postId) {
            throw ApiProblemException::validation('postId', 'postId is only allowed with source=feed.');
        }

        $track = $this->tracks->visible($uriVariables['trackId'] ?? null);
        if (MediaStatus::Ready !== $track->getStatus() || null === $track->getDurationMs()) {
            throw new ApiProblemException(ProblemCode::TrackNotReady, 'The track is not ready.');
        }

        [$counted, $playCount] = $this->recorder->record($user, $track, ListenSource::Player, (int) $input->listenedMs, $track->getDurationMs());

        return new ListenResultView($counted, $playCount);
    }

    private function feedListen(User $user, mixed $trackId, ListenInput $input): ListenResultView
    {
        // Unknown post: same answer as a post that is not playable (no existence oracle).
        $post = null !== $input->postId && Uuid::isValid($input->postId) ? $this->posts->findWithRelations(Uuid::fromString($input->postId)) : null;
        if (null === $post
            || !\is_string($trackId)
            || $post->getTrack()->getId()->toRfc4122() !== strtolower($trackId)
            || !$this->audience->isPostPlayable($post, $user)) {
            throw ApiProblemException::validation('postId', 'source=feed needs a post of this track that you can play.');
        }

        [$counted, $playCount] = $this->recorder->record($user, $post->getTrack(), ListenSource::Feed, (int) $input->listenedMs, $post->getClipDurationMs(), $post->getId());

        return new ListenResultView($counted, $playCount);
    }
}
