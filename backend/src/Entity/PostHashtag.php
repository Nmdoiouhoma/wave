<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** DATA_MODEL §9. Mapped for the schema only: rows are written with SQL at post creation. */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'post_hashtag')]
#[ORM\Index(name: 'idx_post_hashtag_hashtag', columns: ['hashtag_id', 'post_id'])]
class PostHashtag
{
    public function __construct(
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Post::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Post $post,
        #[ORM\Id]
        #[ORM\ManyToOne(targetEntity: Hashtag::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Hashtag $hashtag,
    ) {
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getHashtag(): Hashtag
    {
        return $this->hashtag;
    }
}
