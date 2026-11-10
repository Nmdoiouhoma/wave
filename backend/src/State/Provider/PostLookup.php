<?php

declare(strict_types=1);

namespace App\State\Provider;

use App\Entity\Post;
use App\Entity\User;
use App\Problem\ApiProblemException;
use App\Repository\PostRepository;
use Symfony\Component\Uid\Uuid;

/** Loads a post for a route; unknown and not-visible posts give the same 404 (DATA_MODEL §9). */
final readonly class PostLookup
{
    public function __construct(private PostRepository $posts)
    {
    }

    /** Any existing post, visible or not (callers decide; DELETE needs the author's case). */
    public function find(mixed $id): Post
    {
        $post = \is_string($id) && Uuid::isValid($id) ? $this->posts->findWithRelations(Uuid::fromString($id)) : null;
        if (null === $post) {
            throw ApiProblemException::notFound();
        }

        return $post;
    }

    public function visible(mixed $id, ?User $viewer): Post
    {
        $post = $this->find($id);
        if (!$post->isVisibleTo($viewer)) {
            throw ApiProblemException::notFound();
        }

        return $post;
    }
}
