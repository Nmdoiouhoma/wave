<?php

declare(strict_types=1);

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\StreamUrlView;
use App\Enum\MediaStatus;
use App\Mapper\DateFormat;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use App\Stream\StreamTokenSigner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * `GET /tracks/{trackId}/stream-url`: visibility check (404), readiness (409), then a
 * signed URL valid STREAM_URL_TTL seconds (ADR 001). Answered with `Cache-Control: no-store`.
 *
 * @implements ProviderInterface<StreamUrlView>
 */
final readonly class TrackStreamUrlProvider implements ProviderInterface
{
    public function __construct(
        private TrackLookup $lookup,
        private CurrentUser $currentUser,
        private StreamTokenSigner $signer,
        #[Autowire(env: 'APP_PUBLIC_URL')]
        private string $publicUrl,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreamUrlView
    {
        $viewer = $this->currentUser->require();
        $track = $this->lookup->visible($uriVariables['trackId'] ?? null);
        if (MediaStatus::Ready !== $track->getStatus() || null === $track->getAudioKey()) {
            throw new ApiProblemException(ProblemCode::TrackNotReady, 'The track is still processing or failed.');
        }
        $signed = $this->signer->sign(StreamTokenSigner::KIND_TRACK, $track->getId(), $viewer->getId());

        return new StreamUrlView(rtrim($this->publicUrl, '/').'/api/v1/stream/'.$signed['token'], DateFormat::format($signed['expiresAt']));
    }
}
