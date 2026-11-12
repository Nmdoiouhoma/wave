<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Uid\Uuid;

/**
 * W-33: the auto-hide at the 5th distinct reporter under real concurrency (DATA_MODEL §13).
 * Same harness as ConcurrencySecurityTest: COMMITTED fixtures through a separate connection,
 * one process per request (`Support/concurrent_request.php`), all released at the same instant.
 *
 * A reporter whose visibility check runs after the hide committed gets the plain 404 (the
 * target is no longer visible to them): every response is 201 or 404, at least five are 201.
 */
final class ReportConcurrencySecurityTest extends SecurityTestCase
{
    private const string PREFIX = 'w33c';

    private Connection $raw;

    protected function setUp(): void
    {
        parent::setUp();
        $params = array_filter(
            $this->em()->getConnection()->getParams(),
            static fn (string $k): bool => !str_starts_with($k, 'dama'),
            \ARRAY_FILTER_USE_KEY,
        );
        $this->raw = DriverManager::getConnection($params);
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        $this->raw->close();
        parent::tearDown();
    }

    public function testSixConcurrentReportersHideACommentAndATrackOnce(): void
    {
        $owner = $this->user('owner');
        [$artist, $track] = $this->track($owner);
        $post = $this->post($owner, $track);
        $comment = $this->comment($post, $owner);
        $requests = [];
        for ($i = 0; $i < 6; ++$i) {
            $reporter = $this->user('rep'.$i);
            $requests[] = ['POST', '/api/v1/reports', $reporter, json_encode(['targetType' => 'comment', 'targetId' => $comment, 'reason' => 'spam'], \JSON_THROW_ON_ERROR)];
            $requests[] = ['POST', '/api/v1/reports', $reporter, json_encode(['targetType' => 'track', 'targetId' => $track, 'reason' => 'spam'], \JSON_THROW_ON_ERROR)];
        }

        $statuses = $this->runConcurrently($requests);
        foreach (['comment' => $comment, 'track' => $track] as $type => $id) {
            $mine = array_values(array_filter($statuses, static fn (int $k): bool => ('comment' === $type) === (0 === $k % 2), \ARRAY_FILTER_USE_KEY));
            self::assertSame([], array_diff($mine, [201, 404]), $type.': '.implode(',', $mine));
            $created = \count(array_keys($mine, 201, true));
            self::assertGreaterThanOrEqual(5, $created, $type);
            self::assertSame($created, (int) $this->raw->fetchOne("SELECT count(*) FROM report WHERE target_type = ? AND target_id = ? AND status = 'open'", [$type, $id]));
            self::assertSame('hidden', $this->raw->fetchOne("SELECT moderation FROM {$type} WHERE id = ?", [$id]));
        }
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT comment_count FROM post WHERE id = ?', [$post]));
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT track_count FROM artist WHERE id = ?', [$artist]));
    }

    public function testSixConcurrentReportersHideAPostOnce(): void
    {
        $owner = $this->user('owner');
        [, $track] = $this->track($owner);
        $post = $this->post($owner, $track);
        $requests = [];
        for ($i = 0; $i < 6; ++$i) {
            $requests[] = ['POST', '/api/v1/reports', $this->user('rep'.$i), json_encode(['targetType' => 'post', 'targetId' => $post, 'reason' => 'hate'], \JSON_THROW_ON_ERROR)];
        }

        $statuses = $this->runConcurrently($requests);
        self::assertSame([], array_diff($statuses, [201, 404]), implode(',', $statuses));
        self::assertGreaterThanOrEqual(5, \count(array_keys($statuses, 201, true)));
        self::assertSame('hidden', $this->raw->fetchOne('SELECT moderation FROM post WHERE id = ?', [$post]));
        self::assertSame('visible', $this->raw->fetchOne('SELECT moderation FROM track WHERE id = ?', [$track]));
    }

    // ------------------------------------------------------------- helpers ---

    /**
     * @param list<array{string, string, User, string}> $requests method, uri, as, json body
     *
     * @return list<int> statuses, in request order
     */
    private function runConcurrently(array $requests): array
    {
        $startAt = microtime(true) + 4.0; // every worker has booted its kernel by then
        $procs = array_map(fn (array $r): array => $this->spawn($r[0], $r[1], $r[2], $r[3], $startAt), $requests);

        return array_map(fn (array $p): int => (int) $this->finish($p), $procs);
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function spawn(string $method, string $uri, User $as, string $body, float $startAt): array
    {
        $env = getenv() + ['APP_ENV' => 'test'];
        $env['APP_ENV'] = 'test';
        $cmd = [\PHP_BINARY, __DIR__.'/Support/concurrent_request.php', $method, $uri, $this->tokenFor($as), \sprintf('%.6F', $startAt), $body];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    /** @param array{0: resource, 1: array<int, resource>} $p */
    private function finish(array $p): string
    {
        [$proc, $pipes] = $p;
        $out = trim((string) stream_get_contents($pipes[1]));
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertMatchesRegularExpression('/^\d{3}$/', $out, 'worker failed (exit '.$exit.'): '.$out.' '.substr($err, 0, 2000));

        return $out;
    }

    private function user(string $name): User
    {
        $user = new User(self::PREFIX.$name.'@example.test', self::PREFIX.'_'.$name, ucfirst($name));
        $this->raw->insert('app_user', [
            'id' => $user->getId()->toRfc4122(),
            'email' => self::PREFIX.$name.'@example.test',
            'password' => 'x',
            'username' => self::PREFIX.'_'.$name,
            'display_name' => ucfirst($name),
            'email_verified_at' => 'now()',
            'created_at' => 'now()',
        ]);

        return $user;
    }

    /** @return array{string, string} artist id, track id (track_count = 1) */
    private function track(User $owner): array
    {
        $o = $owner->getId()->toRfc4122();
        $artist = Uuid::v7()->toRfc4122();
        $this->raw->insert('artist', ['id' => $artist, 'owner_id' => $o, 'slug' => self::PREFIX.'-'.substr($o, -12), 'name' => 'Band', 'track_count' => 1, 'created_at' => 'now()']);
        $id = Uuid::v7()->toRfc4122();
        $this->raw->insert('track', [
            'id' => $id, 'artist_id' => $artist, 'owner_id' => $o, 'genre_id' => 'pop', 'title' => 'Song',
            'size_bytes' => 1000, 'status' => 'ready', 'duration_ms' => 180_000,
            'audio_key' => 'audio/tracks/'.$id.'.m4a', 'raw_key' => 'quarantine/tracks/'.$id.'/source',
            'created_at' => 'now()', 'updated_at' => 'now()',
        ]);

        return [$artist, $id];
    }

    private function post(User $author, string $track): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->raw->insert('post', [
            'id' => $id, 'author_id' => $author->getId()->toRfc4122(), 'track_id' => $track,
            'clip_start_ms' => 0, 'clip_duration_ms' => 30_000, 'clip_key' => 'audio/clips/'.$id.'.m4a',
            'status' => 'ready', 'created_at' => 'now()',
        ]);

        return $id;
    }

    private function comment(string $post, User $author): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->raw->insert('comment', ['id' => $id, 'post_id' => $post, 'author_id' => $author->getId()->toRfc4122(), 'body' => 'rude', 'created_at' => 'now()']);
        $this->raw->executeStatement('UPDATE post SET comment_count = 1 WHERE id = ?', [$post]);

        return $id;
    }

    /** Removes every committed row of this class, plus the side tables the workers write to. */
    private function cleanup(): void
    {
        $users = "SELECT id FROM app_user WHERE username LIKE '".self::PREFIX."\\_%'";
        foreach ([
            "DELETE FROM report WHERE reporter_id IN ($users)",
            "DELETE FROM comment WHERE author_id IN ($users)",
            "DELETE FROM post WHERE author_id IN ($users)",
            "DELETE FROM track WHERE owner_id IN ($users)",
            "DELETE FROM artist WHERE owner_id IN ($users)",
            "DELETE FROM refresh_token WHERE username IN (SELECT id::text FROM app_user WHERE username LIKE '".self::PREFIX."\\_%')",
            "DELETE FROM app_user WHERE id IN ($users)",
            // Nothing else is ever committed in the test DB (DAMA): these come from the workers.
            'DELETE FROM messenger_messages',
            'DELETE FROM lock_keys',
            'DELETE FROM cache_items',
        ] as $sql) {
            $this->raw->executeStatement($sql);
        }
    }
}
