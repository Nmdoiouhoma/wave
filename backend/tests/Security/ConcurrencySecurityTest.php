<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Uid\Uuid;

/**
 * W-26: denormalised counters and playlist positions under real concurrency. Fixtures are
 * COMMITTED through a separate connection (outside the DAMA transaction) and each request runs
 * in its own process (`Support/concurrent_request.php`, own kernel and connection), all
 * released at the same instant. tearDown deletes everything this class committed.
 */
final class ConcurrencySecurityTest extends SecurityTestCase
{
    private const string PREFIX = 'w26c';

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

    public function testTwentyConcurrentLikesFromTwentyUsersCountTwenty(): void
    {
        $owner = $this->user('owner');
        $track = $this->track($owner);
        $requests = [];
        for ($i = 0; $i < 20; ++$i) {
            $requests[] = ['PUT', '/api/v1/tracks/'.$track.'/like', $this->user('fan'.$i), ''];
        }

        self::assertSame(array_fill(0, 20, 200), $this->runConcurrently($requests));
        self::assertSame(20, (int) $this->raw->fetchOne('SELECT like_count FROM track WHERE id = ?', [$track]));
        self::assertSame(20, (int) $this->raw->fetchOne('SELECT count(*) FROM track_like WHERE track_id = ?', [$track]));
    }

    public function testTwentyConcurrentLikesFromOneUserCountOnce(): void
    {
        $owner = $this->user('owner');
        $track = $this->track($owner);
        $fan = $this->user('fan');

        self::assertSame(array_fill(0, 20, 200), $this->runConcurrently(array_fill(0, 20, ['PUT', '/api/v1/tracks/'.$track.'/like', $fan, ''])));
        self::assertSame(1, (int) $this->raw->fetchOne('SELECT like_count FROM track WHERE id = ?', [$track]));
    }

    public function testConcurrentFollowsKeepBothCountersExact(): void
    {
        $star = $this->user('star');
        $requests = [];
        for ($i = 0; $i < 15; ++$i) {
            $requests[] = ['PUT', '/api/v1/users/'.self::PREFIX.'_star/follow', $this->user('fan'.$i), ''];
        }
        // One user following the star 5 times at once: still one edge.
        $twice = $this->user('twice');
        for ($i = 0; $i < 5; ++$i) {
            $requests[] = ['PUT', '/api/v1/users/'.self::PREFIX.'_star/follow', $twice, ''];
        }

        self::assertSame(array_fill(0, 20, 200), $this->runConcurrently($requests));
        self::assertSame(16, (int) $this->raw->fetchOne('SELECT followers_count FROM app_user WHERE id = ?', [$star->getId()->toRfc4122()]));
        self::assertSame(1, (int) $this->raw->fetchOne('SELECT following_count FROM app_user WHERE id = ?', [$twice->getId()->toRfc4122()]));
        self::assertSame(16, (int) $this->raw->fetchOne('SELECT count(*) FROM follow WHERE followed_id = ?', [$star->getId()->toRfc4122()]));
    }

    public function testConcurrentAppendsKeepPositionsContiguous(): void
    {
        $owner = $this->user('owner');
        $playlist = $this->playlist($owner);
        $requests = [];
        for ($i = 0; $i < 10; ++$i) {
            $requests[] = ['POST', '/api/v1/playlists/'.$playlist.'/items', $owner, (string) json_encode(['trackId' => $this->track($owner, 'T'.$i)])];
        }

        self::assertSame(array_fill(0, 10, 201), $this->runConcurrently($requests));
        self::assertSame(10, (int) $this->raw->fetchOne('SELECT item_count FROM playlist WHERE id = ?', [$playlist]));
        self::assertSame(range(0, 9), array_map('intval', $this->raw->fetchFirstColumn('SELECT position FROM playlist_item WHERE playlist_id = ? ORDER BY position', [$playlist])));
    }

    public function testConcurrentListenReportsCountOnceInTheWindow(): void
    {
        $owner = $this->user('owner');
        $track = $this->track($owner);
        $fan = $this->user('fan');
        $body = (string) json_encode(['listenedMs' => 60_000, 'source' => 'player']);
        // ADR 010 (W-26 F13): a stream URL was issued two minutes ago.
        $this->raw->executeStatement("INSERT INTO listen_grant (user_id, track_id, issued_at) VALUES (?, ?, now() - interval '2 minutes')", [$fan->getId()->toRfc4122(), $track]);

        self::assertSame(array_fill(0, 10, 200), $this->runConcurrently(array_fill(0, 10, ['POST', '/api/v1/tracks/'.$track.'/listens', $fan, $body])));
        self::assertSame(1, (int) $this->raw->fetchOne('SELECT play_count FROM track WHERE id = ?', [$track]));
        self::assertSame(1, (int) $this->raw->fetchOne('SELECT count(*) FROM listen WHERE track_id = ?', [$track]));
    }

    /**
     * Deleting a track while its owner's playlist gets an append: `detachTrackEverywhere()`
     * shifts positions without taking the playlist lock first, so the shift misses the row
     * being appended. Deterministic: this connection plays the append (lock held, row inserted),
     * the worker deletes the track and blocks on the playlist row until we commit.
     * FINDING if it fails: a gap in positions, then the next append hits the unique position.
     */
    public function testTrackDeletionRacingAnAppendKeepsPositionsContiguous(): void
    {
        $owner = $this->user('owner');
        $playlist = $this->playlist($owner);
        $first = $this->track($owner, 'First');
        $second = $this->track($owner, 'Second');
        $third = $this->track($owner, 'Third');
        foreach ([$first, $second] as $i => $t) {
            $this->raw->insert('playlist_item', ['id' => Uuid::v7()->toRfc4122(), 'playlist_id' => $playlist, 'track_id' => $t, 'position' => $i, 'added_at' => 'now()']);
        }
        $this->raw->executeStatement('UPDATE playlist SET item_count = 2 WHERE id = ?', [$playlist]);

        // Append of $third, exactly as PlaylistItemProcessor + PlaylistRepository::append do it.
        $this->raw->beginTransaction();
        $this->raw->fetchOne('SELECT item_count FROM playlist WHERE id = ? FOR UPDATE', [$playlist]);
        $this->raw->executeStatement('INSERT INTO playlist_item (id, playlist_id, track_id, position, added_at) SELECT ?, p.id, ?, p.item_count, clock_timestamp() FROM playlist p WHERE p.id = ?', [Uuid::v7()->toRfc4122(), $third, $playlist]);
        $this->raw->executeStatement('UPDATE playlist SET item_count = item_count + 1 WHERE id = ?', [$playlist]);
        $proc = $this->spawn('DELETE', '/api/v1/tracks/'.$first, $owner, '', microtime(true));
        usleep(1_500_000); // the worker boots, shifts what it sees, then waits for our lock
        $this->raw->commit();
        self::assertSame('204', $this->finish($proc));

        self::assertSame(2, (int) $this->raw->fetchOne('SELECT item_count FROM playlist WHERE id = ?', [$playlist]));
        self::assertSame([0, 1], array_map('intval', $this->raw->fetchFirstColumn('SELECT position FROM playlist_item WHERE playlist_id = ? ORDER BY position', [$playlist])));
    }

    /** W-26 F15: 6 concurrent creations at 198 playlists → exactly 200, the rest 409. */
    public function testConcurrentCreationsStopExactlyAtThePlaylistLimit(): void
    {
        $owner = $this->user('owner');
        $o = $owner->getId()->toRfc4122();
        $this->raw->executeStatement(
            "INSERT INTO playlist (id, owner_id, name, created_at, updated_at)
             SELECT gen_random_uuid(), ?, 'P' || g, now(), now() FROM generate_series(1, 198) g",
            [$o],
        );
        $body = (string) json_encode(['name' => 'Race']);

        $statuses = $this->runConcurrently(array_fill(0, 6, ['POST', '/api/v1/playlists', $owner, $body]));
        sort($statuses);
        self::assertSame([201, 201, 409, 409, 409, 409], $statuses);
        self::assertSame(200, (int) $this->raw->fetchOne('SELECT count(*) FROM playlist WHERE owner_id = ?', [$o]));
    }

    /**
     * Variant of F11: the track is appended to a playlist that did NOT contain it yet. The
     * deletion locks only the playlists it sees, then the cascade removes the appended item
     * once the append commits. FINDING if it fails: item_count stays one too high.
     */
    public function testTrackDeletionRacingAnAppendToAnotherPlaylistKeepsItemCountExact(): void
    {
        $owner = $this->user('owner');
        $playlist = $this->playlist($owner);
        $track = $this->track($owner, 'Doomed');

        $this->raw->beginTransaction();
        $this->raw->fetchOne('SELECT item_count FROM playlist WHERE id = ? FOR UPDATE', [$playlist]);
        $this->raw->executeStatement('INSERT INTO playlist_item (id, playlist_id, track_id, position, added_at) SELECT ?, p.id, ?, p.item_count, clock_timestamp() FROM playlist p WHERE p.id = ?', [Uuid::v7()->toRfc4122(), $track, $playlist]);
        $this->raw->executeStatement('UPDATE playlist SET item_count = item_count + 1 WHERE id = ?', [$playlist]);
        $proc = $this->spawn('DELETE', '/api/v1/tracks/'.$track, $owner, '', microtime(true));
        usleep(1_500_000); // the worker boots, finds no playlist to lock, waits on the track row (FK key share)
        $this->raw->commit();
        self::assertSame('204', $this->finish($proc));

        $items = (int) $this->raw->fetchOne('SELECT count(*) FROM playlist_item WHERE playlist_id = ?', [$playlist]);
        self::assertSame(0, $items);
        self::assertSame($items, (int) $this->raw->fetchOne('SELECT item_count FROM playlist WHERE id = ?', [$playlist]), 'item_count drifted');
    }

    /**
     * Reverse order: the deletion holds the track row, an append of that track arrives. FINDING
     * if it fails: the FK violation surfaces as a 500 instead of a 404.
     */
    public function testAppendRacingATrackDeletionIsNotA500(): void
    {
        $owner = $this->user('owner');
        $playlist = $this->playlist($owner);
        $track = $this->track($owner, 'Doomed');

        $this->raw->beginTransaction();
        $this->raw->executeStatement('DELETE FROM track WHERE id = ?', [$track]);
        $proc = $this->spawn('POST', '/api/v1/playlists/'.$playlist.'/items', $owner, (string) json_encode(['trackId' => $track]), microtime(true));
        usleep(1_500_000); // the worker sees the track (snapshot), locks the playlist, blocks on the FK
        $this->raw->commit();
        $status = $this->finish($proc);

        self::assertContains($status, ['404', '409'], 'append of a track deleted meanwhile → '.$status);
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT item_count FROM playlist WHERE id = ?', [$playlist]));
    }

    /**
     * W-47: adding a close friend while that user unfollows never leaves a member without a
     * follow edge (composite FK + conditional insert), and neither side answers 500.
     */
    public function testCloseFriendAddRacingAnUnfollowNeverLeavesAMemberWithoutFollow(): void
    {
        $owner = $this->user('owner');
        $requests = [];
        for ($i = 0; $i < 8; ++$i) {
            $fan = $this->user('cf'.$i);
            $this->raw->insert('follow', ['follower_id' => $fan->getId()->toRfc4122(), 'followed_id' => $owner->getId()->toRfc4122(), 'created_at' => 'now()']);
            $this->raw->executeStatement('UPDATE app_user SET following_count = 1 WHERE id = ?', [$fan->getId()->toRfc4122()]);
            $requests[] = ['PUT', '/api/v1/me/close-friends/'.self::PREFIX.'_cf'.$i, $owner, ''];
            $requests[] = ['DELETE', '/api/v1/users/'.self::PREFIX.'_owner/follow', $fan, ''];
        }
        $this->raw->executeStatement('UPDATE app_user SET followers_count = 8 WHERE id = ?', [$owner->getId()->toRfc4122()]);

        $statuses = $this->runConcurrently($requests);
        foreach ($statuses as $i => $status) {
            self::assertContains($status, 0 === $i % 2 ? [200, 422] : [200], $requests[$i][0].' '.$requests[$i][1]);
        }
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT count(*) FROM follow WHERE followed_id = ?', [$owner->getId()->toRfc4122()]));
        self::assertSame(0, (int) $this->raw->fetchOne(
            'SELECT count(*) FROM close_friend cf WHERE NOT EXISTS (SELECT 1 FROM follow f WHERE f.follower_id = cf.friend_id AND f.followed_id = cf.owner_id)',
        ));
        self::assertSame(0, (int) $this->raw->fetchOne('SELECT count(*) FROM close_friend WHERE owner_id = ?', [$owner->getId()->toRfc4122()]));
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

    private function track(User $owner, string $title = 'Song'): string
    {
        $o = $owner->getId()->toRfc4122();
        $artist = $this->raw->fetchOne('SELECT id FROM artist WHERE owner_id = ?', [$o]);
        if (false === $artist) {
            $artist = Uuid::v7()->toRfc4122();
            $this->raw->insert('artist', ['id' => $artist, 'owner_id' => $o, 'slug' => self::PREFIX.'-'.substr($o, -12), 'name' => 'Band', 'created_at' => 'now()']);
        }
        $id = Uuid::v7()->toRfc4122();
        $this->raw->insert('track', [
            'id' => $id, 'artist_id' => $artist, 'owner_id' => $o, 'genre_id' => 'pop', 'title' => $title,
            'size_bytes' => 1000, 'status' => 'ready', 'duration_ms' => 180_000,
            'audio_key' => 'audio/tracks/'.$id.'.m4a', 'raw_key' => 'quarantine/tracks/'.$id.'/source',
            'created_at' => 'now()', 'updated_at' => 'now()',
        ]);

        return $id;
    }

    private function playlist(User $owner): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->raw->insert('playlist', ['id' => $id, 'owner_id' => $owner->getId()->toRfc4122(), 'name' => 'Mix', 'created_at' => 'now()', 'updated_at' => 'now()']);

        return $id;
    }

    /** Removes every committed row of this class, plus the side tables the workers write to. */
    private function cleanup(): void
    {
        $users = "SELECT id FROM app_user WHERE username LIKE '".self::PREFIX."\\_%'";
        foreach ([
            "DELETE FROM listen WHERE user_id IN ($users)",
            "DELETE FROM track_like WHERE user_id IN ($users)",
            "DELETE FROM playlist_like WHERE user_id IN ($users)",
            "DELETE FROM follow WHERE follower_id IN ($users) OR followed_id IN ($users)",
            "DELETE FROM playlist WHERE owner_id IN ($users)",
            "DELETE FROM track WHERE owner_id IN ($users)",
            "DELETE FROM artist WHERE owner_id IN ($users)",
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
