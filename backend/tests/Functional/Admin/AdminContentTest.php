<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Message\PurgeObjects;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** W-71: `GET /admin/content` and `POST /admin/content/{type}/{id}/deletion` (ADR 013). */
final class AdminContentTest extends WaveApiTestCase
{
    public function testListIncludesHiddenItemsFiltersAndPages(): void
    {
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $artist = $this->createArtist($alice);
        $first = $this->createTrack($artist, 'Électrique Nuit');
        $second = $this->createTrack($artist, 'Hidden Song');
        $third = $this->createTrack($this->createArtist($bob, 'Bob Band'), 'Morning');
        $this->sql("UPDATE track SET moderation = 'hidden' WHERE id = ?", [$second->getId()]);
        $this->seedReport($bob, 'track', $second->getId());

        $page = $this->json($this->api('GET', '/api/v1/admin/content?type=track&limit=2', $mod));
        self::assertSame([(string) $third->getId(), (string) $second->getId()], array_column($page['items'], 'id'));
        self::assertSame(['track', 'Hidden Song', 'hidden', 'ready', 'public', 1], [
            $page['items'][1]['type'], $page['items'][1]['label'], $page['items'][1]['moderation'],
            $page['items'][1]['status'], $page['items'][1]['visibility'], $page['items'][1]['openReportCount'],
        ]);
        self::assertSame('alice', $page['items'][1]['owner']['username']);
        $next = $this->json($this->api('GET', '/api/v1/admin/content?type=track&limit=2&cursor='.$page['nextCursor'], $mod));
        self::assertSame([(string) $first->getId()], array_column($next['items'], 'id'));
        self::assertNull($next['nextCursor']);

        self::assertSame(['Hidden Song'], $this->labels('type=track&moderation=hidden', $mod));
        self::assertSame(['Morning'], $this->labels('type=track&ownerId='.$bob->getId(), $mod));
        self::assertSame(['Électrique Nuit'], $this->labels('type=track&q=electrique', $mod));
        self::assertSame(['Bob Band', 'Some Artist'], $this->labels('type=artist', $mod));

        $post = $this->makePost($bob, $third);
        $comment = $this->comment($post, $alice, 'nice one');
        $postItem = $this->json($this->api('GET', '/api/v1/admin/content?type=post', $mod))['items'][0];
        self::assertSame(['Morning', null], [$postItem['label'], $postItem['visibility']]);
        self::assertSame([$comment], array_column($this->json($this->api('GET', '/api/v1/admin/content?type=comment', $mod))['items'], 'id'));

        $this->assertProblem($this->api('GET', '/api/v1/admin/content', $mod), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/admin/content?type=user', $mod), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/admin/content?type=track&ownerId=nope', $mod), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/admin/content?type=track&q=a', $mod), 400, 'malformed_request');
        $this->assertProblem($this->api('GET', '/api/v1/admin/content?type=track', $alice), 403, 'forbidden');
    }

    public function testDeletionIsAdminOnlyBeforeAnyLookup(): void
    {
        $mod = $this->staff('mod', 'ROLE_MODERATOR');
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $unknown = Uuid::v7();

        $this->assertProblem($this->api('POST', '/api/v1/admin/content/track/'.$unknown.'/deletion', $mod, ['json' => ['note' => 'x']]), 403, 'forbidden');
        $this->assertProblem($this->api('POST', '/api/v1/admin/content/track/'.$unknown.'/deletion', $admin, ['json' => ['note' => 'x']]), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/admin/content/user/'.$admin->getId().'/deletion', $admin, ['json' => ['note' => 'x']]), 404, 'not_found');
        $this->assertProblem($this->api('POST', '/api/v1/admin/content/track/'.$unknown.'/deletion', $admin, ['json' => []]), 422, 'validation_failed');
        self::assertSame([], $this->adminEvents());
    }

    public function testTrackDeletionReusesOwnerRulesAndJournalsOneRow(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($alice));
        $post = $this->makePost($bob, $track);
        $this->seedReport($bob, 'track', $track->getId());
        $this->seedReport($alice, 'post', $post->getId());

        $response = $this->api('POST', '/api/v1/admin/content/track/'.$track->getId().'/deletion', $admin, ['json' => ['note' => 'copyright']]);
        self::assertSame(204, $response->getStatusCode(), $response->getContent(false));

        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM track'));
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM post'), 'posts on the track go with it');
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT count(*) FROM report'), 'reports of the track and of its posts');
        self::assertNotSame([], $this->queued('async', PurgeObjects::class));
        self::assertSame([
            ['admin.content_deleted', (string) $admin->getId(), (string) $alice->getId(), 'track', (string) $track->getId(), 'copyright'],
        ], $this->adminEvents());
    }

    public function testCommentDeletionFixesTheCounterAndArtistWithTracksConflicts(): void
    {
        $admin = $this->staff('boss', 'ROLE_ADMIN');
        $alice = $this->createUser('alice');
        $artist = $this->createArtist($alice);
        $post = $this->makePost($alice, $this->createTrack($artist));
        $comment = $this->comment($post, $alice, 'rude');

        self::assertSame(204, $this->api('POST', '/api/v1/admin/content/comment/'.$comment.'/deletion', $admin, ['json' => ['note' => 'insult']])->getStatusCode());
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT comment_count FROM post WHERE id = ?', [(string) $post->getId()]));

        $this->assertProblem($this->api('POST', '/api/v1/admin/content/artist/'.$artist->getId().'/deletion', $admin, ['json' => ['note' => 'x']]), 409, 'artist_has_tracks');
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT count(*) FROM artist'));

        $empty = $this->createArtist($alice, 'Empty');
        self::assertSame(204, $this->api('POST', '/api/v1/admin/content/artist/'.$empty->getId().'/deletion', $admin, ['json' => ['note' => 'fake']])->getStatusCode());
        $events = $this->adminEvents();
        self::assertSame([['admin.content_deleted', 'comment', $comment, 'insult'], ['admin.content_deleted', 'artist', (string) $empty->getId(), 'fake']], array_map(static fn (array $e): array => [$e[0], $e[3], $e[4], $e[5]], $events));
        $metadata = json_decode((string) $this->db()->fetchOne("SELECT metadata FROM activity_event WHERE target_type = 'comment'"), true);
        self::assertSame('comment', $metadata['contentType']);
    }

    private function staff(string $username, string $role): User
    {
        $user = $this->createUser($username);
        $user->setRoles([$role]);
        $this->em()->flush();

        return $user;
    }

    /** @return list<string> */
    private function labels(string $query, User $as): array
    {
        return array_column($this->json($this->api('GET', '/api/v1/admin/content?'.$query, $as))['items'], 'label');
    }

    private function makePost(User $author, Track $track): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        $this->em()->clear();

        return $post;
    }

    private function comment(Post $post, User $author, string $body): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->sql("INSERT INTO comment (id, post_id, author_id, body, moderation, created_at) VALUES (?, ?, ?, ?, 'visible', now())", [$id, $post->getId(), $author->getId(), $body]);
        $this->sql('UPDATE post SET comment_count = comment_count + 1 WHERE id = ?', [$post->getId()]);

        return $id;
    }

    private function seedReport(User $reporter, string $type, Uuid $id): void
    {
        $this->sql(
            "INSERT INTO report (id, reporter_id, target_type, target_id, reason, status, created_at) VALUES (?, ?, ?, ?, 'spam', 'open', now())",
            [Uuid::v7(), $reporter->getId(), $type, $id],
        );
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params): void
    {
        $this->db()->executeStatement($sql, array_map(static fn (mixed $p): mixed => $p instanceof Uuid ? $p->toRfc4122() : $p, $params));
        $this->em()->clear();
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    /** @return list<list<string|null>> type, actor, subject, target type, target id, note — oldest first */
    private function adminEvents(): array
    {
        return array_map(array_values(...), $this->db()->fetchAllAssociative(
            "SELECT type, actor_id::text, subject_user_id::text, target_type, target_id::text, metadata->>'note' FROM activity_event WHERE type LIKE 'admin.%' ORDER BY id",
        ));
    }
}
