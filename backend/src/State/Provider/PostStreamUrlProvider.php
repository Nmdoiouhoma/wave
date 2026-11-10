<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\StreamUrlView;
use App\Enum\MediaStatus;
use App\Mapper\PostMapper;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\ListenGrantRepository;
use App\Security\CurrentUser;

/**
 * `GET /posts/{postId}/stream-url`: visibility (404), readiness (409 `track_not_ready`), then a
 * signed clip URL (token kind `c`) and the listen grant of (caller, post.track) with the clip
 * duration as reference (DATA_MODEL §9, §11).
 *
 * @implements ProviderInterface<StreamUrlView>
 */
final readonly class PostStreamUrlProvider implements ProviderInterface
{
    public function __construct(
        private PostLookup $lookup,
        private CurrentUser $currentUser,
        private PostMapper $mapper,
        private ListenGrantRepository $grants,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreamUrlView
    {
        $viewer = $this->currentUser->require();
        $post = $this->lookup->visible($uriVariables['postId'] ?? null, $viewer);
        if (MediaStatus::Ready !== $post->getStatus() || null === $post->getClipKey()) {
            throw new ApiProblemException(ProblemCode::TrackNotReady, 'The post is still processing or failed.');
        }
        $this->grants->grant($viewer->getId(), $post->getTrack()->getId(), $post->getClipDurationMs());

        return $this->mapper->signClip($post, $viewer);
    }
}
