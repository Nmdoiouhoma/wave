<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\Input\PostViewsInput;
use App\Http\JsonInputDecoder;
use App\Repository\PostViewRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /post-views` (204 whatever the ids: no existence oracle). Duplicates in a batch are merged
 * (max); own, unknown and not-playable posts are ignored (DATA_MODEL §11); `view_count` + 1 only on
 * the first view, in the same transaction as the upsert.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class PostViewsProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private PostViewRepository $views,
        private EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, PostViewsInput::class);

        $merged = [];
        foreach ($input->views ?? [] as $item) {
            /** @var array{postId: string, watchedMs: int} $item */
            $id = strtolower($item['postId']);
            $merged[$id] = max($merged[$id] ?? 0, $item['watchedMs']);
        }
        ksort($merged);
        $this->em->wrapInTransaction(fn (): int => $this->views->record($user, $merged));

        return null;
    }
}
