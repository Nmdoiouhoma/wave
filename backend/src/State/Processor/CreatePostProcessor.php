<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\PostView;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Enum\Moderation;
use App\Enum\Visibility;
use App\Enum\VisualType;
use App\Http\RequestEncodingListener;
use App\Http\ResponseHeadersListener;
use App\Mapper\PostMapper;
use App\Message\GenerateClip;
use App\Message\ProcessPostVisual;
use App\Post\HashtagExtractor;
use App\Post\VisualSniffer;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use App\Security\TrackAudience;
use App\State\Provider\TrackLookup;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use App\Upload\QuarantineWriter;
use App\Upload\UploadedFileValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /posts` (multipart, W-30). Checks, in the contract's order: form fields (422), track
 * visible to the caller (404, no existence oracle), clips allowed (422 `clips_not_allowed`),
 * own track ready (409 `track_not_ready`), clip range (422 `clip_out_of_range`), then the visual
 * (413/415, content sniffed) — all before any row is written. Then one transaction: post row,
 * hashtags (upsert + counters), visual to quarantine, `GenerateClip` (+ `ProcessPostVisual`).
 *
 * @implements ProcessorInterface<mixed, PostView>
 */
final readonly class CreatePostProcessor implements ProcessorInterface
{
    public const int VIDEO_MAX_BYTES = 15_728_640;
    private const array FIELDS = ['trackId', 'clipStartMs', 'clipDurationMs', 'caption'];

    public function __construct(
        private CurrentUser $currentUser,
        private TrackLookup $tracks,
        private QuarantineWriter $quarantine,
        private ObjectStorage $storage,
        private EntityManagerInterface $em,
        private MessageBusInterface $bus,
        private PostMapper $mapper,
        private TrackAudience $audience,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PostView
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];

        [$trackId, $clipStartMs, $clipDurationMs, $caption] = $this->readFields($request);
        $file = $this->readVisualFile($request);

        $track = $this->tracks->visible($trackId);
        $this->assertClippable($track, $user);
        if ($clipStartMs + $clipDurationMs > (int) $track->getDurationMs()) {
            throw new ApiProblemException(ProblemCode::ClipOutOfRange, 'clipStartMs + clipDurationMs must not exceed the track duration.');
        }
        $visualType = null === $file ? null : $this->checkVisual($file);

        $postId = Uuid::v7();
        $rawKey = 'quarantine/posts/'.$postId->toRfc4122().'/visual';
        $post = $this->em->wrapInTransaction(function () use ($user, $track, $clipStartMs, $clipDurationMs, $caption, $postId, $file, $visualType, $rawKey): Post {
            $post = new Post($user, $track, $clipStartMs, $clipDurationMs, $caption, $postId);
            if (null !== $file && null !== $visualType) {
                $post->attachRawVisual($visualType, $rawKey);
            }
            $this->em->persist($post);
            $this->em->flush();
            $this->attachHashtags($postId, HashtagExtractor::extract($caption));

            if (null === $file) {
                $this->bus->dispatch(new GenerateClip($postId->toRfc4122()));

                return $post;
            }
            $this->quarantine->write($file, $rawKey);
            try {
                $this->bus->dispatch(new GenerateClip($postId->toRfc4122()));
                $this->bus->dispatch(new ProcessPostVisual($postId->toRfc4122()));
            } catch (\Throwable $e) {
                $this->storage->delete(StorageArea::Private, $rawKey);
                throw $e;
            }

            return $post;
        });

        $request->attributes->set(ResponseHeadersListener::LOCATION_ATTRIBUTE, '/api/v1/posts/'.$post->getId()->toRfc4122());

        return $this->mapper->toView($post, $user);
    }

    /** @return array{string, int, int, ?string} */
    private function readFields(Request $request): array
    {
        $fields = $request->request->all();
        $files = $request->files->all();
        if ([] === $fields && [] === $files && (int) $request->server->get('CONTENT_LENGTH', 0) > 0) {
            // PHP dropped the whole body (post_max_size exceeded).
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, 'The request body is too large.');
        }
        foreach (array_keys($fields) as $name) {
            if (!\in_array($name, self::FIELDS, true)) {
                throw ApiProblemException::validation((string) $name, 'Unknown field.');
            }
        }
        foreach (array_keys($files) as $name) {
            if ('visual' !== $name) {
                throw ApiProblemException::validation((string) $name, 'Unknown field.');
            }
        }
        foreach ($fields as $name => $value) {
            if (!\is_string($value) || !RequestEncodingListener::isCleanText($value)) {
                throw ApiProblemException::validation((string) $name, 'Must be a single valid UTF-8 text value.');
            }
        }

        $trackId = $fields['trackId'] ?? null;
        if (!\is_string($trackId) || !Uuid::isValid($trackId)) {
            throw ApiProblemException::validation('trackId', 'A track id (uuid) is required.');
        }
        $clipStartMs = $this->integer($fields, 'clipStartMs');
        $clipDurationMs = $this->integer($fields, 'clipDurationMs');
        if ($clipDurationMs < Post::MIN_CLIP_MS || $clipDurationMs > Post::MAX_CLIP_MS) {
            throw ApiProblemException::validation('clipDurationMs', \sprintf('Must be between %d and %d.', Post::MIN_CLIP_MS, Post::MAX_CLIP_MS));
        }
        $caption = $fields['caption'] ?? null;
        $caption = \is_string($caption) && '' !== trim($caption) ? $caption : null;
        if (null !== $caption && mb_strlen($caption) > Post::CAPTION_MAX) {
            throw ApiProblemException::validation('caption', \sprintf('At most %d characters.', Post::CAPTION_MAX));
        }

        return [$trackId, $clipStartMs, $clipDurationMs, $caption];
    }

    /** @param array<array-key, mixed> $fields */
    private function integer(array $fields, string $name): int
    {
        $value = $fields[$name] ?? null;
        if (!\is_string($value) || 1 !== preg_match('/^[0-9]{1,9}$/', $value)) {
            throw ApiProblemException::validation($name, 'A non-negative integer is required.');
        }

        return (int) $value;
    }

    private function readVisualFile(Request $request): ?UploadedFile
    {
        if (!$request->files->has('visual')) {
            return null;
        }
        $file = $request->files->get('visual');
        if (!$file instanceof UploadedFile) {
            throw ApiProblemException::validation('visual', 'A single file is expected.');
        }

        return $file;
    }

    /** DATA_MODEL §9 creation rule (D5) and the contract's error order. */
    private function assertClippable(Track $track, User $user): void
    {
        if ($track->isOwnedBy($user)) {
            if (Moderation::Visible !== $track->getModeration()) {
                throw new ApiProblemException(ProblemCode::ClipsNotAllowed, 'This track cannot be clipped.');
            }
            if (MediaStatus::Ready !== $track->getStatus() || null === $track->getDurationMs()) {
                throw new ApiProblemException(ProblemCode::TrackNotReady, 'The track is still processing or failed.');
            }

            return;
        }
        // A moderator can see others' private/processing/hidden tracks: for posting, they are
        // just another user, so such a track is "not visible" (same 404 as an unknown id).
        if (!$this->audience->isTrackVisible($track, $user, false)) {
            throw ApiProblemException::notFound();
        }
        // FB-18: others' followers/close_friends track the caller can see → still not clippable.
        if (Visibility::Public !== $track->getVisibility()) {
            throw new ApiProblemException(ProblemCode::ClipsNotAllowed, 'Only public tracks can be clipped by others.');
        }
        if (!$track->isAllowClips()) {
            throw new ApiProblemException(ProblemCode::ClipsNotAllowed, 'The owner of this track does not allow clips.');
        }
    }

    /** 413 / 415 before any row is written; the type comes from the content, not the name. */
    private function checkVisual(UploadedFile $file): VisualType
    {
        if (\in_array($file->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)) {
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, 'The visual is too large.');
        }
        if (\UPLOAD_ERR_OK !== $file->getError() || !$file->isValid()) {
            throw ApiProblemException::validation('visual', 'The upload failed, please retry.');
        }
        $size = (int) $file->getSize();
        if ($size > self::VIDEO_MAX_BYTES) {
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, \sprintf('The visual must not exceed %d bytes.', self::VIDEO_MAX_BYTES));
        }
        if (0 === $size) {
            throw ApiProblemException::validation('visual', 'The file is empty.');
        }
        $type = VisualSniffer::sniff($file->getPathname());
        if (null === $type) {
            throw new ApiProblemException(ProblemCode::UnsupportedMediaType, 'Allowed visuals: JPEG, PNG or WebP image, MP4 video.');
        }
        if (VisualType::Image === $type && $size > UploadedFileValidator::IMAGE_MAX_BYTES) {
            throw new ApiProblemException(ProblemCode::PayloadTooLarge, \sprintf('An image must not exceed %d bytes.', UploadedFileValidator::IMAGE_MAX_BYTES));
        }

        return $type;
    }

    /**
     * Upserts the hashtags (sorted, so concurrent posts lock rows in the same order) with
     * `post_count + 1`, then links them to the post. Same transaction as the post.
     *
     * @param list<string> $names
     */
    private function attachHashtags(Uuid $postId, array $names): void
    {
        if ([] === $names) {
            return;
        }
        sort($names, \SORT_STRING);
        $values = [];
        $params = ['p' => $postId->toRfc4122()];
        foreach ($names as $i => $name) {
            $values[] = "(:id{$i}, :n{$i}, 1, clock_timestamp())";
            $params["id{$i}"] = Uuid::v7()->toRfc4122();
            $params["n{$i}"] = $name;
        }
        $conn = $this->em->getConnection();
        $ids = $conn->fetchFirstColumn(
            'INSERT INTO hashtag (id, name, post_count, created_at) VALUES '.implode(', ', $values).'
             ON CONFLICT (name) DO UPDATE SET post_count = hashtag.post_count + 1 RETURNING id',
            $params,
        );
        foreach ($ids as $hashtagId) {
            $conn->executeStatement('INSERT INTO post_hashtag (post_id, hashtag_id) VALUES (:p, :h)', ['p' => $params['p'], 'h' => $hashtagId]);
        }
    }
}
