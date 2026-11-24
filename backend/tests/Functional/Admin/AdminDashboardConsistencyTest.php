<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Command\Seed\SeedJournal;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Repository\PostRepository;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * fb-1124: the « Activité 24 h » card (tables) and today's bucket of the content series (journal)
 * must agree on the same data, demo data included (the seed bypasses the processors).
 */
final class AdminDashboardConsistencyTest extends WaveApiTestCase
{
    private const string DEMO = '%@wave.test';

    public function testCardAndTodaySeriesAgreeOnSeededAndRealContent(): void
    {
        $admin = $this->createUser('boss');
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em()->flush();
        $alice = $this->createUser('alice', email: 'alice@wave.test');
        $bob = $this->createUser('bob', email: 'bob@wave.test');
        $carol = $this->createUser('carol');

        // Demo content written without the processors, like the seed: two today, one backdated each.
        $track = $this->createTrack($this->createArtist($alice));
        $old = $this->createTrack($this->createArtist($bob, 'Old'));
        $this->sql("UPDATE track SET created_at = now() - interval '3 days' WHERE id = ?", [$old->getId()]);
        $post = $this->post($alice, $track, 'now');
        $this->post($bob, $track, '-2 days');
        $this->sql("INSERT INTO comment (id, moderation, created_at, body, post_id, author_id) VALUES (?, 'visible', now(), 'demo', ?, ?)", [Uuid::v7(), $post->getId(), $bob->getId()]);
        // A content event left behind by an older seed (its author deleted by SQL: actor NULL).
        $this->sql("INSERT INTO activity_event (id, category, type, target_type, target_id, metadata, created_at) VALUES (?, 'content', 'content.post_created', 'post', ?, '{}', now())", [UuidV7::generate(), Uuid::v7()]);
        // Real content through the API, journaled by its processor.
        $r = $this->api('POST', '/api/v1/posts/'.$post->getId().'/comments', $carol, ['json' => ['body' => 'nice']]);
        self::assertSame(201, $r->getStatusCode(), $r->getContent(false));

        $journal = new SeedJournal($this->db());
        $journal->forgetDemo(self::DEMO);
        self::assertSame(2 + 2 + 2 + 1, $journal->backfill(self::DEMO), '2 accounts, 2 tracks, 2 posts, 1 demo comment');

        $card = $this->json($this->api('GET', '/api/v1/admin/dashboard', $admin))['content'];
        $series = $this->json($this->api('GET', '/api/v1/admin/dashboard/series', $admin));
        $today = array_map(static fn (array $s): int => $s[6], $series['content']);

        self::assertSame(['tracks24h' => 1, 'posts24h' => 1, 'comments24h' => 2], array_intersect_key($card, array_flip(['tracks24h', 'posts24h', 'comments24h'])));
        self::assertSame(
            [$card['tracks24h'], $card['posts24h'], $card['comments24h']],
            [$today['tracks'], $today['posts'], $today['comments']],
            'card and series count the same content',
        );
        self::assertSame(2, $series['activity']['registrations'][6], 'demo accounts journaled');
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT count(*) FROM activity_event WHERE type = 'content.track_created' AND created_at < now() - interval '2 days'"), 'backdated like the content');
    }

    private function post(User $author, Track $track, string $at): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        $this->sql('UPDATE post SET created_at = ? WHERE id = ?', [new \DateTimeImmutable($at)->format('Y-m-d H:i:s.uP'), $post->getId()]);
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        $this->em()->clear();

        return $post;
    }

    private function db(): \Doctrine\DBAL\Connection
    {
        return $this->em()->getConnection();
    }

    /** @param list<mixed> $params */
    private function sql(string $sql, array $params = []): void
    {
        $this->db()->executeStatement($sql, array_map(static fn (mixed $p): mixed => $p instanceof Uuid ? $p->toRfc4122() : $p, $params));
    }
}
