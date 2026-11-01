<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ListenResultView;
use App\Dto\Input\ListenInput;
use App\Enum\ListenSource;
use App\Enum\MediaStatus;
use App\Http\JsonInputDecoder;
use App\Listen\ListenRecorder;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use App\State\Provider\TrackLookup;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /tracks/{trackId}/listens` (200 counted or not). Phase 2: `source=player` only;
 * `postId` is forbidden with it, and `source=feed` (needs a playable post, W-30) is a 422.
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
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ListenResultView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, ListenInput::class);

        if (ListenSource::Feed === $input->source) {
            throw ApiProblemException::validation('postId', 'Feed listens need a playable post of this track (posts arrive in phase 3).');
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
}
