<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\CommentView;
use App\Dto\Input\CreateCommentInput;
use App\Entity\Comment;
use App\Enum\NotificationType;
use App\Http\JsonInputDecoder;
use App\Mapper\CommentMapper;
use App\Post\VisibleText;
use App\Problem\ApiProblemException;
use App\Repository\CommentRepository;
use App\Repository\NotificationRepository;
use App\Security\CurrentUser;
use App\State\Provider\PostLookup;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /posts/{postId}/comments` (201): post playable for the caller (DATA_MODEL §9, else the
 * same 404 as an unknown id); body trimmed, 1–500 characters after trim (else 422); stored as
 * plain text, and notifies the post's author (DATA_MODEL §8b) in the same transaction. Verified e-mail and the `comment` limits are enforced before (gate, listener).
 *
 * @implements ProcessorInterface<mixed, CommentView>
 */
final readonly class CreateCommentProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private PostLookup $posts,
        private CommentRepository $comments,
        private CommentMapper $mapper,
        private NotificationRepository $notifications,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CommentView
    {
        $user = $this->currentUser->require();
        $post = $this->posts->playable($uriVariables['postId'] ?? null, $user);
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, CreateCommentInput::class);

        // Unicode whitespace, separators and invisible format characters at both ends (F22).
        $body = VisibleText::trim((string) $input->body);
        $length = mb_strlen($body);
        if ($length < 1 || $length > Comment::BODY_MAX) {
            throw ApiProblemException::validation('body', 'The comment must contain 1 to '.Comment::BODY_MAX.' characters.');
        }

        $comment = new Comment($post, $user, $body);
        $this->em->wrapInTransaction(function () use ($comment, $post, $user): void {
            $this->comments->create($comment);
            $this->notifications->recordOn(NotificationType::Comment, $user, $post->getId());
        });

        return $this->mapper->toView($comment, $user);
    }
}
