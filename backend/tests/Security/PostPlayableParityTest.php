<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Feed\FeedRanker;
use App\Repository\PostRepository;
use App\Security\TrackAudience;
use App\Tests\Functional\WaveApiTestCase;

/**
 * Phase 3 wave 2 QA: "playable for the caller" (DATA_MODEL §9, FB-18) is written five times —
 * TrackAudience::isPostPlayable (PHP), postPlayableSql (views), postPlayableDql (following feed),
 * the hand-written DQL of PostRepository::findPageByAuthor and SimpleFeedRanker::candidateSql
 * (for-you). One matrix of posts, each clause of the rule broken once, checked against all five
 * for a follower V (close friend of some authors) and a stranger W.
 */
final class PostPlayableParityTest extends WaveApiTestCase
{
    /** @var array<string, array{post: Post, author: User, v: bool, w: bool}> */
    private array $cases = [];
    private User $v;
    private User $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->v = $this->createUser('viewer');
        $this->w = $this->createUser('stranger');

        // name => [track owned by another user?, visibility, allowClips, SQL breaking a clause, V close friend, playable for V, for W]
        /** @var array<string, array{bool, Visibility, bool, string|null, bool, bool, bool}> $matrix */
        $matrix = [
            'pub_clips' => [false, Visibility::Public, true, null, false, true, true],
            'pub_noclips_own' => [false, Visibility::Public, false, null, false, true, true],
            'other_pub_clips' => [true, Visibility::Public, true, null, false, true, true],
            'other_pub_noclips' => [true, Visibility::Public, false, null, false, false, false],
            'post_hidden' => [false, Visibility::Public, true, "UPDATE post SET moderation = 'hidden' WHERE id = :p", false, false, false],
            'track_hidden' => [false, Visibility::Public, true, "UPDATE track SET moderation = 'hidden' WHERE id = :t", false, false, false],
            'author_suspended' => [false, Visibility::Public, true, 'UPDATE app_user SET suspended_at = now() WHERE id = :a', false, false, false],
            'post_processing' => [false, Visibility::Public, true, "UPDATE post SET status = 'processing' WHERE id = :p", false, false, false],
            'post_failed' => [false, Visibility::Public, true, "UPDATE post SET status = 'failed' WHERE id = :p", false, false, false],
            'own_followers' => [false, Visibility::Followers, false, null, false, true, false],
            'own_cf_outside' => [false, Visibility::CloseFriends, false, null, false, false, false],
            'own_cf_member' => [false, Visibility::CloseFriends, false, null, true, true, false],
            'own_private' => [false, Visibility::Private, false, null, false, false, false],
            'other_followers' => [true, Visibility::Followers, true, null, false, false, false],
            'other_cf_member' => [true, Visibility::CloseFriends, true, null, true, false, false],
        ];
        $db = $this->em()->getConnection();
        $i = 0;
        foreach ($matrix as $name => [$other, $visibility, $clips, $break, $close, $forV, $forW]) {
            ++$i;
            $author = $this->createUser('author'.$i);
            $owner = $other ? $this->createUser('owner'.$i) : $author;
            $track = $this->createTrack($this->createArtist($owner, 'Artist '.$i), 'T'.$i, true, $visibility, $clips);
            $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
            $this->em()->persist($post);
            $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
            $this->em()->flush();
            self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
            foreach (array_unique([$author->getId()->toRfc4122(), $owner->getId()->toRfc4122()]) as $followed) {
                $db->executeStatement('INSERT INTO follow (follower_id, followed_id, created_at) VALUES (:f, :o, now())', ['f' => $this->v->getId()->toRfc4122(), 'o' => $followed]);
                if ($close) {
                    $db->executeStatement('INSERT INTO close_friend (owner_id, friend_id, created_at) VALUES (:o, :f, now())', ['f' => $this->v->getId()->toRfc4122(), 'o' => $followed]);
                }
            }
            if (null !== $break) {
                $params = ['p' => $post->getId()->toRfc4122(), 't' => $track->getId()->toRfc4122(), 'a' => $author->getId()->toRfc4122()];
                preg_match_all('/:([pta])\b/', $break, $m);
                $db->executeStatement($break, array_intersect_key($params, array_flip($m[1])));
            }
            $this->cases[$name] = ['post' => $post, 'author' => $author, 'v' => $forV, 'w' => $forW];
        }
        $this->em()->clear();
    }

    public function testEveryImplementationOfPlayableAgrees(): void
    {
        foreach (['v' => $this->v, 'w' => $this->w] as $key => $viewer) {
            $viewer = $this->managed($viewer);
            $expected = $this->expected($key);
            self::assertSame($expected, $this->viaPhp($viewer), "isPostPlayable, viewer $key");
            self::assertSame($expected, $this->viaSql($viewer), "postPlayableSql, viewer $key");
            self::assertSame($expected, $this->viaForYou($viewer), "for-you candidates, viewer $key");
            self::assertSame($expected, $this->viaAuthorPages($viewer), "findPageByAuthor(playable), viewer $key");
        }
        // The following feed is limited to followed authors: V follows every author.
        self::assertSame($this->expected('v'), $this->viaFollowingFeed($this->managed($this->v)), 'postPlayableDql (following feed), viewer v');
    }

    /** @return list<string> */
    private function expected(string $viewer): array
    {
        $names = array_keys(array_filter($this->cases, static fn (array $c): bool => true === $c[$viewer]));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private function viaPhp(User $viewer): array
    {
        $audience = static::getContainer()->get(TrackAudience::class);
        $repo = static::getContainer()->get(PostRepository::class);

        return $this->names(array_filter(array_map(
            static fn (array $c): ?string => ($p = $repo->findWithRelations($c['post']->getId())) && $audience->isPostPlayable($p, $viewer) ? $p->getId()->toRfc4122() : null,
            $this->cases,
        )));
    }

    /** @return list<string> */
    private function viaSql(User $viewer): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn(
            'SELECT p.id FROM post p JOIN track t ON t.id = p.track_id JOIN app_user u ON u.id = p.author_id WHERE '.TrackAudience::postPlayableSql('p', 't', 'u'),
            ['v' => $viewer->getId()->toRfc4122()],
        );

        return $this->names(array_map('strval', $ids));
    }

    /** @return list<string> */
    private function viaForYou(User $viewer): array
    {
        $ranker = static::getContainer()->get(FeedRanker::class);
        $ids = [];
        $cursor = null;
        do {
            $page = $ranker->forYou($viewer->getId(), $cursor, 20);
            foreach ($page->postIds as $id) {
                $ids[] = $id->toRfc4122();
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor);

        return $this->names($ids);
    }

    /** @return list<string> */
    private function viaAuthorPages(User $viewer): array
    {
        $repo = static::getContainer()->get(PostRepository::class);
        $ids = [];
        foreach ($this->cases as $c) {
            foreach ($repo->findPageByAuthor($this->managed($c['author']), $viewer, 'playable', null, 10) as $p) {
                $ids[] = $p->getId()->toRfc4122();
            }
        }

        return $this->names($ids);
    }

    /** @return list<string> */
    private function viaFollowingFeed(User $viewer): array
    {
        $rows = static::getContainer()->get(PostRepository::class)->findFollowingPage($viewer, null, 100);

        return $this->names(array_map(static fn (Post $p): string => $p->getId()->toRfc4122(), $rows));
    }

    /**
     * @param array<array-key, string> $ids
     *
     * @return list<string>
     */
    private function names(array $ids): array
    {
        $byId = [];
        foreach ($this->cases as $name => $c) {
            $byId[$c['post']->getId()->toRfc4122()] = $name;
        }
        $names = array_map(static fn (string $id): string => $byId[$id] ?? 'unknown:'.$id, array_values($ids));
        sort($names);

        return $names;
    }
}
