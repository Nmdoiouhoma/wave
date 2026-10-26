<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\TrackUploadSlotView;
use App\Dto\Input\CreateTrackUploadInput;
use App\Entity\TrackUpload;
use App\Http\JsonInputDecoder;
use App\Mapper\DateFormat;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\TrackUploadRepository;
use App\Security\CurrentUser;
use App\Storage\ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /tracks/uploads` (ADR 009 step 1): declared type (415) and size (413) pre-checks,
 * at most 10 pending uploads (409), then a `track_upload` row and a presigned S3 POST
 * policy bound to `quarantine/uploads/{userId}/{uploadId}` with the size range.
 *
 * @implements ProcessorInterface<mixed, TrackUploadSlotView>
 */
final readonly class CreateTrackUploadProcessor implements ProcessorInterface
{
    /** Declared MIME types and extensions accepted as a pre-filter (the worker re-checks). */
    public const array CONTENT_TYPES = ['audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/x-m4a', 'video/mp4'];
    public const array EXTENSIONS = ['mp3', 'm4a', 'aac', 'mp4'];

    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private TrackUploadRepository $uploads,
        private ObjectStorage $storage,
        private EntityManagerInterface $em,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TrackUploadSlotView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, CreateTrackUploadInput::class);

        $filename = trim((string) $input->filename);
        $extension = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        $contentType = strtolower(trim((string) $input->contentType));
        if (!\in_array($extension, self::EXTENSIONS, true) || !\in_array($contentType, self::CONTENT_TYPES, true)) {
            throw new ApiProblemException(ProblemCode::UnsupportedMediaType, 'Accepted files: .mp3, .m4a, .aac, .mp4.');
        }
        $maxBytes = TrackUpload::maxBytesFor($extension);
        if ((int) $input->size > $maxBytes) {
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, \sprintf('This file must not exceed %d bytes.', $maxBytes));
        }

        $upload = $this->em->wrapInTransaction(function () use ($user, $filename, $input, $contentType, $maxBytes): TrackUpload {
            // Serialise concurrent slot creations of one user so the pending limit holds.
            $this->em->getConnection()->executeQuery('SELECT 1 FROM app_user WHERE id = :id FOR UPDATE', ['id' => $user->getId()->toRfc4122()]);
            if ($this->uploads->countPending($user) >= TrackUpload::MAX_PENDING_PER_USER) {
                throw new ApiProblemException(ProblemCode::TooManyPendingUploads, \sprintf('Finish or wait for your %d pending uploads first.', TrackUpload::MAX_PENDING_PER_USER));
            }
            $upload = new TrackUpload($user, mb_substr($filename, 0, 255), (int) $input->size, $contentType, $maxBytes, $this->clock->now());
            $this->em->persist($upload);
            $this->em->flush();

            return $upload;
        });

        $post = $this->storage->presignedPost($upload->getQuarantineKey(), $maxBytes, $upload->getExpiresAt());

        return new TrackUploadSlotView(
            $upload->getId()->toRfc4122(),
            'POST',
            $post->url,
            $post->fields,
            $maxBytes,
            DateFormat::format($upload->getExpiresAt()),
        );
    }
}
