<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\PostView;
use App\ApiResource\PostVisualView;
use App\ApiResource\StreamUrlView;
use App\ApiResource\TrackView;
use App\Entity\Post;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Repository\LikeRepository;
use App\Repository\ListenGrantRepository;
use App\Repository\PostRepository;
use App\Storage\ObjectStorage;
use App\Stream\StreamTokenSigner;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Post payloads, batched for a page (likes, hashtags, tracks: one query each). `clip` is signed
 * only for ready posts visible to the caller, and every issued clip writes the caller's listen
 * grant for the post's track (DATA_MODEL §9, §11) in one multi-row upsert per response.
 */
final readonly class PostMapper
{
    public function __construct(
        private TrackMapper $tracks,
        private UserMapper $users,
        private LikeRepository $likes,
        private PostRepository $posts,
        private ListenGrantRepository $grants,
        private StreamTokenSigner $signer,
        private ObjectStorage $storage,
        #[Autowire(env: 'APP_PUBLIC_URL')]
        private string $publicUrl,
    ) {
    }

    public function toView(Post $post, ?User $viewer): PostView
    {
        return $this->toViews([$post], $viewer)[0];
    }

    /**
     * @param list<Post> $posts
     *
     * @return list<PostView>
     */
    public function toViews(array $posts, ?User $viewer): array
    {
        if ([] === $posts) {
            return [];
        }
        $ids = array_map(static fn (Post $p): Uuid => $p->getId(), $posts);
        $liked = $this->likes->likedAmong('post', $viewer, $ids);
        $hashtags = $this->posts->hashtagsOf($ids);

        $uniqueTracks = [];
        foreach ($posts as $post) {
            $uniqueTracks[$post->getTrack()->getId()->toRfc4122()] = $post->getTrack();
        }
        $trackViews = [];
        foreach ($this->tracks->toViews(array_values($uniqueTracks), $viewer) as $view) {
            $trackViews[$view->id] = $view;
        }

        $grants = [];
        $views = [];
        foreach ($posts as $post) {
            $id = $post->getId()->toRfc4122();
            $clip = null;
            if (null !== $viewer && MediaStatus::Ready === $post->getStatus() && null !== $post->getClipKey() && $post->isVisibleTo($viewer)) {
                $clip = $this->signClip($post, $viewer);
                $trackId = $post->getTrack()->getId()->toRfc4122();
                $grants[$trackId] = max($grants[$trackId] ?? 0, $post->getClipDurationMs());
            }
            $views[] = $this->build($post, $viewer, $trackViews[$post->getTrack()->getId()->toRfc4122()], $clip, $hashtags[$id] ?? [], isset($liked[$id]));
        }
        if (null !== $viewer) {
            $this->grants->grantMany($viewer->getId(), $grants);
        }

        return $views;
    }

    /** Signed clip URL (token kind `c`, ADR 001); the caller writes the grant. */
    public function signClip(Post $post, User $viewer): StreamUrlView
    {
        $signed = $this->signer->sign(StreamTokenSigner::KIND_CLIP, $post->getId(), $viewer->getId());

        return new StreamUrlView(rtrim($this->publicUrl, '/').'/api/v1/stream/'.$signed['token'], DateFormat::format($signed['expiresAt']));
    }

    /** @param list<string> $hashtags */
    private function build(Post $post, ?User $viewer, TrackView $track, ?StreamUrlView $clip, array $hashtags, bool $likedByMe): PostView
    {
        $visual = null;
        if (null !== $post->getVisualType() && null !== $post->getVisualKey()) {
            $poster = $post->getVisualPosterKey();
            $visual = new PostVisualView($post->getVisualType()->value, $this->storage->publicUrl($post->getVisualKey()), null === $poster ? null : $this->storage->publicUrl($poster));
        }

        return new PostView(
            id: $post->getId()->toRfc4122(),
            author: $this->users->toSummary($post->getAuthor()),
            track: $track,
            clipStartMs: $post->getClipStartMs(),
            clipDurationMs: $post->getClipDurationMs(),
            clip: $clip,
            visual: $visual,
            caption: $post->getCaption(),
            hashtags: $hashtags,
            status: $post->getStatus()->value,
            failureReason: $post->getFailureReason()?->value,
            moderation: $post->getModeration()->value,
            likeCount: $post->getLikeCount(),
            commentCount: $post->getCommentCount(),
            viewCount: $post->getViewCount(),
            likedByMe: $likedByMe,
            isOwner: $post->isAuthoredBy($viewer),
            createdAt: DateFormat::format($post->getCreatedAt()),
        );
    }
}
