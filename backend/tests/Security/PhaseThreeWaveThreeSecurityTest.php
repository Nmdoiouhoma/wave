<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\PostRepository;

/**
 * QA phase 3, wave 3 (W-33 reports/moderation, W-45 notifications), docs/handoff/phase3-v3-qa.md.
 * Tests named `testOpen…` fail on purpose: each one is an open finding until fixed.
 */
final class PhaseThreeWaveThreeSecurityTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    /** Point 2: the reporter coming after the auto-hide gets the unknown-id 404, byte for byte (no oracle). */
    public function testReporterAfterAutoHideGetsTheUnknownIdResponse(): void
    {
        $alice = $this->createUser('alice');
        $post = $this->makePost($alice, $this->createTrack($this->createArtist($alice)));
        $receipts = [];
        for ($i = 0; $i < 5; ++$i) {
            $r = $this->report($this->createUser('rep'.$i), 'post', (string) $post->getId());
            self::assertSame(201, $r->getStatusCode(), $r->getContent(false));
            $receipts[] = array_keys($this->json($r));
        }
        self::assertCount(1, array_unique(array_map('serialize', $receipts)));
        self::assertSame('hidden', $this->em()->getConnection()->fetchOne('SELECT moderation FROM post WHERE id = ?', [(string) $post->getId()]));

        $late = $this->createUser('late');
        $unknown = $this->report($late, 'post', self::UNKNOWN);
        $hidden = $this->report($late, 'post', (string) $post->getId());
        self::assertSame(404, $hidden->getStatusCode());
        self::assertSame($unknown->getContent(false), $hidden->getContent(false));
        self::assertSame($this->stableHeaders($unknown->getHeaders(false)), $this->stableHeaders($hidden->getHeaders(false)));
    }

    /** Point 2: roles are reloaded per request, a demoted moderator's still-valid JWT no longer opens /admin. */
    public function testDemotedModeratorLosesAdminWithAStillValidJwt(): void
    {
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $jwt = $this->tokenFor($mod);
        $auth = ['headers' => ['Authorization' => 'Bearer '.$jwt]];
        self::assertSame(200, $this->api('GET', '/api/v1/admin/report-targets', null, $auth)->getStatusCode());

        $this->em()->getConnection()->executeStatement("UPDATE app_user SET roles = '[]' WHERE id = ?", [(string) $mod->getId()]);
        $this->em()->clear();
        $this->assertProblem($this->api('GET', '/api/v1/admin/report-targets', null, $auth), 403, 'forbidden');
    }

    /** Point 2: an encoded path does not slip past the `^/api/v1/admin` access_control. */
    public function testEncodedAdminPathIsStillForbidden(): void
    {
        $bob = $this->createUser('bob');
        $track = $this->createTrack($this->createArtist($this->createUser('alice')));
        foreach (['/api/v1/%61dmin/report-targets', '/api/v1/admin/report-targets/', '/api/v1/admin/report-targets/track/'.$track->getId().'/reports'] as $uri) {
            $status = $this->api('GET', $uri, $bob)->getStatusCode();
            self::assertContains($status, [403, 404], $uri);
        }
    }

    /**
     * Fixed (medium, W-45): `updatedAt` is sent and compared exactly at microsecond precision, so an item
     * that arrived after the list was displayed, in the same second as the newest displayed item,
     * stays unread (contract: "an event arriving meanwhile stays unread").
     */
    public function testItemArrivedInTheSameSecondAfterDisplayStaysUnread(): void
    {
        $alice = $this->createUser('alice');
        $shown = $this->createTrack($this->createArtist($alice, 'A0', 'a0'), 'Shown');
        $late = $this->createTrack($this->createArtist($alice, 'A1', 'a1'), 'Late');
        $this->api('PUT', '/api/v1/tracks/'.$shown->getId().'/like', $this->createUser('bob'));
        $this->setUpdatedAt($shown, '2026-01-01 10:00:00.200+00');

        $page = $this->json($this->api('GET', '/api/v1/me/notifications', $alice));
        self::assertCount(1, $page['items']);
        $upTo = $page['items'][0]['updatedAt'];
        self::assertSame('2026-01-01T10:00:00.200000+00:00', $upTo);

        // Arrives after the page was rendered, same wall-clock second.
        $this->api('PUT', '/api/v1/tracks/'.$late->getId().'/like', $this->createUser('carol'));
        $this->setUpdatedAt($late, '2026-01-01 10:00:00.800+00');

        self::assertSame(204, $this->api('POST', '/api/v1/me/notifications/read', $alice, ['json' => ['upTo' => $upTo]])->getStatusCode());
        self::assertSame(['unreadCount' => 1], $this->json($this->api('GET', '/api/v1/me/notifications/unread-count', $alice)), 'the never-displayed item was marked read');
    }

    /**
     * Fixed (medium, W-33): deleting a reported playlist or artist deletes its reports in the same
     * transaction (ReportRepository::deleteForTarget), like comments, tracks and posts
     * (CommentRepository, PostCleanup); no target stays in the queue with `preview: null`.
     */
    public function testDeletedPlaylistOrArtistLeavesNoStuckQueueEntry(): void
    {
        $alice = $this->createUser('alice');
        $bob = $this->createUser('bob');
        $playlist = $this->createPlaylist($alice, 'Mix');
        $artist = $this->createArtist($alice, 'Empty', 'empty-artist');
        self::assertSame(201, $this->report($bob, 'playlist', (string) $playlist->getId())->getStatusCode());
        self::assertSame(201, $this->report($bob, 'artist', (string) $artist->getId())->getStatusCode());
        self::assertSame(204, $this->api('DELETE', '/api/v1/playlists/'.$playlist->getId(), $alice)->getStatusCode());
        self::assertSame(204, $this->api('DELETE', '/api/v1/artists/empty-artist', $alice)->getStatusCode());

        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $queue = $this->json($this->api('GET', '/api/v1/admin/report-targets', $mod));
        self::assertSame([], array_column($queue['items'], 'targetId'), 'deleted targets stay in the open queue');
    }

    // ------------------------------------------------------------- helpers ---

    private function report(User $as, string $type, string $id): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        return $this->api('POST', '/api/v1/reports', $as, ['json' => ['targetType' => $type, 'targetId' => $id, 'reason' => 'spam']]);
    }

    private function setUpdatedAt(Track $track, string $at): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE notification SET updated_at = ? WHERE track_id = ?', [$at, (string) $track->getId()]);
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array<string, list<string>>
     */
    private function stableHeaders(array $headers): array
    {
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link'], $headers['x-ratelimit-remaining']);
        ksort($headers);

        return $headers;
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
}
