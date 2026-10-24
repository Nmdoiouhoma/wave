<?php

declare(strict_types=1);

namespace App\Stream;

use App\Entity\Track;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\TrackRepository;
use App\Repository\UserRepository;
use App\Storage\ObjectStorage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * `GET|HEAD /api/v1/stream/{token}` (ADR 001): no JWT, no session, no rate limiter. Verifies
 * the signature (403) and expiry (410), re-checks that the media exists, is ready and is
 * still visible to the token's viewer (404), then lets nginx serve the bytes with Range
 * support through `X-Accel-Redirect`.
 */
final readonly class StreamController
{
    public function __construct(
        private StreamTokenSigner $signer,
        private TrackRepository $tracks,
        private UserRepository $users,
        private ObjectStorage $storage,
    ) {
    }

    #[Route('/api/v1/stream/{token}', name: 'streamAudio', requirements: ['token' => '[A-Za-z0-9_.-]{1,512}'], methods: ['GET', 'HEAD'])]
    public function __invoke(string $token): Response
    {
        $grant = $this->signer->verify($token);

        $audioKey = match ($grant->kind) {
            StreamTokenSigner::KIND_TRACK => $this->trackAudio($grant),
            // Post clips arrive with W-30 (phase 3).
            default => throw new ApiProblemException(ProblemCode::NotFound),
        };

        $response = new Response('', Response::HTTP_OK, [
            'X-Accel-Redirect' => $this->storage->internalRedirectPath($audioKey),
            'Content-Type' => 'audio/mp4',
        ]);
        $response->headers->set('Cache-Control', 'private, max-age=300');

        return $response;
    }

    private function trackAudio(StreamGrant $grant): string
    {
        $track = $this->tracks->findWithRelations($grant->id);
        $viewer = $this->users->find($grant->viewerId);
        if (!$track instanceof Track || !$viewer instanceof User || $viewer->isSuspended()
            || MediaStatus::Ready !== $track->getStatus() || null === $track->getAudioKey() || !$track->isVisibleTo($viewer)) {
            throw ApiProblemException::notFound();
        }

        return $track->getAudioKey();
    }
}
