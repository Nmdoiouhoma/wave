<?php

declare(strict_types=1);

namespace App\Tests\Security;

/**
 * Phase 3 wave 3 review (W-33, W-45), docs/handoff/phase3-v3-qa.md.
 *
 * testReadDoesNotMarkAnItemNeverDisplayed covers finding "moyen 1" (fixed): notification `updatedAt`
 * is serialized and compared exactly at microsecond precision, so an item arriving in the same second as the
 * newest displayed one stays unread.
 */
final class WaveThreeSecurityTest extends SecurityTestCase
{
    private const string LIST = '/api/v1/me/notifications';
    private const string READ = '/api/v1/me/notifications/read';

    public function testReadDoesNotMarkAnItemNeverDisplayed(): void
    {
        $alice = $this->createUser('alice');
        $track = $this->createTrack($this->createArtist($alice), 'Night');
        self::assertSame(200, $this->api('PUT', '/api/v1/tracks/'.$track->getId().'/like', $this->createUser('fan'))->getStatusCode());
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE notification SET updated_at = '2026-11-12 10:00:00.100+00'");

        // The client displays the like item…
        $displayed = $this->json($this->api('GET', self::LIST, $alice))['items'];
        self::assertCount(1, $displayed);
        self::assertSame('2026-11-12T10:00:00.100000+00:00', $displayed[0]['updatedAt']);

        // …then a follow arrives 800 ms later, in the same second, and is never displayed.
        self::assertSame(200, $this->api('PUT', '/api/v1/users/alice/follow', $this->createUser('late'))->getStatusCode());
        $db->executeStatement("UPDATE notification SET updated_at = '2026-11-12 10:00:00.900+00' WHERE type = 'follow'");

        $r = $this->api('POST', self::READ, $alice, ['json' => ['upTo' => $displayed[0]['updatedAt']]]);
        self::assertSame(204, $r->getStatusCode(), $r->getContent(false));

        self::assertNotNull($db->fetchOne("SELECT read_at FROM notification WHERE type = 'track_like'"), 'the displayed item is read');
        // Contract (openapi `markMyNotificationsRead`): "an event arriving meanwhile stays unread".
        self::assertNull($db->fetchOne("SELECT read_at FROM notification WHERE type = 'follow'"), 'an item never displayed must stay unread');
    }

    /** Point 1 of the review: the shared ViewNormalizer change only affects `\stdClass` maps. */
    public function testEmptyMapsAreObjectsAndEmptyListsStayArrays(): void
    {
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $track = $this->createTrack($this->createArtist($this->createUser('alice')));

        // Proactive decision on a target without any report: `reasons` is `{}`.
        $r = $this->api('POST', '/api/v1/admin/report-targets/track/'.$track->getId().'/decision', $mod, ['json' => ['action' => 'dismiss']]);
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));
        self::assertStringContainsString('"reasons":{}', (string) $r->getContent());

        // Empty lists elsewhere stay JSON arrays.
        self::assertStringContainsString('"items":[]', (string) $this->api('GET', '/api/v1/admin/report-targets', $mod)->getContent());
        self::assertStringContainsString('"items":[]', (string) $this->api('GET', self::LIST, $mod)->getContent());
    }
}
