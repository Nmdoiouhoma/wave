<?php

declare(strict_types=1);

namespace App\Tests\Functional\Feed;

use App\Feed\FeedRanker;
use App\Feed\ForYouCursor;
use App\Feed\SimpleFeedRanker;
use App\Pagination\CursorCodec;
use App\Tests\Functional\WaveApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Group;

/**
 * W-39 performance budget: 5 000 posts / 200 users (20 % on restricted tracks), viewer following
 * 50 users and in 10 close-friends lists; every page < 100 ms locally. Writes the EXPLAIN ANALYZE
 * of both phase queries to var/w39-explain.txt (pasted in docs/handoff/w39-backend.md).
 */
#[Group('performance')]
final class ForYouPerformanceTest extends WaveApiTestCase
{
    private const float BUDGET_MS = 100.0;

    public function testPagesStayUnderBudget(): void
    {
        $viewer = $this->createUser('viewer');
        $v = $viewer->getId()->toRfc4122();
        $db = $this->em()->getConnection();
        $this->seed($db, $v);

        $ranker = static::getContainer()->get(FeedRanker::class);
        $timings = [];
        $cursor = null;
        $seen = [];
        for ($i = 0; $i < 5; ++$i) {
            $t = hrtime(true);
            $page = $ranker->forYou($viewer->getId(), $cursor, 10);
            $timings['u'.$i] = (hrtime(true) - $t) / 1e6;
            self::assertCount(10, $page->postIds);
            $seen = [...$seen, ...array_map('strval', $page->postIds)];
            $cursor = $page->nextCursor;
        }
        self::assertSame($seen, array_values(array_unique($seen)));

        // Phase s from its start, same session.
        self::assertIsString($cursor);
        $codec = static::getContainer()->get(CursorCodec::class);
        $payload = $codec->decodePayload($cursor);
        $sCursor = $codec->encodePayload(['p' => 's', 'k' => ['2000-01-01T00:00:00.000000Z', '00000000-0000-0000-0000-000000000000']] + $payload);
        for ($i = 0; $i < 3; ++$i) {
            $t = hrtime(true);
            $page = $ranker->forYou($viewer->getId(), $sCursor, 10);
            $timings['s'.$i] = (hrtime(true) - $t) / 1e6;
            self::assertNotSame([], $page->postIds);
            $sCursor = (string) $page->nextCursor;
        }

        // Whole HTTP request (ranking + hydration + mapping), first page of new sessions; the
        // first request of the test kernel warms routing and the serializer, it is not timed.
        self::assertSame(200, $this->api('GET', '/api/v1/feed/for-you', $viewer)->getStatusCode());
        for ($i = 0; $i < 2; ++$i) {
            $t = hrtime(true);
            $r = $this->api('GET', '/api/v1/feed/for-you', $viewer);
            $timings['http'.$i] = (hrtime(true) - $t) / 1e6;
            self::assertSame(200, $r->getStatusCode());
        }

        $this->writeExplain($db, $v, $payload);
        fwrite(\STDERR, "\nW-39 timings (ms): ".json_encode(array_map(static fn (float $x): float => round($x, 1), $timings))."\n");
        foreach ($timings as $name => $ms) {
            self::assertLessThan(self::BUDGET_MS, $ms, "page {$name}");
        }
    }

    private function seed(Connection $db, string $v): void
    {
        foreach ([
            "INSERT INTO app_user (id, email, password, username, display_name, roles, created_at) SELECT md5('u' || i)::uuid, 'perf' || i || '@example.test', 'x', 'perf_' || i, 'Perf ' || i, '[]', now() FROM generate_series(1, 199) i",
            "INSERT INTO artist (id, owner_id, slug, name, created_at) SELECT md5('a' || i)::uuid, md5('u' || i)::uuid, 'perf-' || i, 'Perf ' || i, now() FROM generate_series(1, 199) i",
            "INSERT INTO track (id, artist_id, owner_id, genre_id, title, audio_key, duration_ms, size_bytes, status, visibility, allow_clips, created_at, updated_at) SELECT md5('t' || j)::uuid, md5('a' || (1 + j % 199))::uuid, md5('u' || (1 + j % 199))::uuid, (ARRAY['rap', 'rap-fr', 'hip-hop', 'pop', 'afro', 'rnb'])[1 + j % 6], 'Track ' || j, 'audio/tracks/x.m4a', 180000, 1000, 'ready', CASE WHEN j % 5 <> 0 THEN 'public' ELSE (ARRAY['followers', 'close_friends', 'private'])[1 + (j / 5) % 3] END, true, now(), now() FROM generate_series(1, 1000) j",
            "INSERT INTO post (id, author_id, track_id, clip_start_ms, clip_duration_ms, clip_key, status, moderation, like_count, comment_count, view_count, trending_score, created_at) SELECT md5('p' || k)::uuid, CASE WHEN (k % 1000) % 5 = 0 THEN md5('u' || (1 + (k % 1000) % 199))::uuid ELSE md5('u' || (1 + (k * 7) % 199))::uuid END, md5('t' || (1 + k % 1000))::uuid, 0, 30000, 'audio/clips/x.m4a', 'ready', 'visible', k % 97, k % 13, k % 211, 0, now() - make_interval(hours => k % 720) FROM generate_series(1, 5000) k",
        ] as $sql) {
            $db->executeStatement($sql);
        }
        // Restricted posts: author = track owner (DATA_MODEL §9 creation rule).
        $db->executeStatement("UPDATE post p SET author_id = t.owner_id FROM track t WHERE t.id = p.track_id AND t.visibility <> 'public'");
        $db->executeStatement(
            "INSERT INTO post (id, author_id, track_id, clip_start_ms, clip_duration_ms, clip_key, status, moderation, like_count, comment_count, view_count, trending_score, created_at)
             SELECT md5('own' || k)::uuid, :v, md5('t' || k)::uuid, 0, 30000, 'x', 'ready', 'visible', 0, 0, 0, 0, now() FROM generate_series(1, 20) k",
            ['v' => $v],
        );
        $db->executeStatement("INSERT INTO follow (follower_id, followed_id, created_at) SELECT :v, md5('u' || i)::uuid, now() FROM generate_series(1, 50) i", ['v' => $v]);
        $db->executeStatement("INSERT INTO close_friend (owner_id, friend_id, created_at) SELECT md5('u' || i)::uuid, :v, now() FROM generate_series(1, 10) i", ['v' => $v]);
        $db->executeStatement("INSERT INTO track_like (user_id, track_id, created_at) SELECT :v, md5('t' || j)::uuid, now() - make_interval(days => j % 120) FROM generate_series(1, 60) j", ['v' => $v]);
        $db->executeStatement("INSERT INTO post_like (user_id, post_id, created_at) SELECT :v, md5('p' || k)::uuid, now() FROM generate_series(1, 5000, 50) k", ['v' => $v]);
        $db->executeStatement(
            "INSERT INTO post_view (user_id, post_id, watched_ms, completed, first_seen_at, last_seen_at)
             SELECT :v, md5('p' || k)::uuid, 1000, false, now() - interval '30 days', clock_timestamp() - make_interval(mins => k) FROM generate_series(1, 5000, 5) k",
            ['v' => $v],
        );
        $db->executeStatement('ANALYZE app_user, artist, track, post, follow, close_friend, track_like, post_like, post_view');
    }

    /** @param array<mixed> $payload a phase-u cursor payload of this session */
    private function writeExplain(Connection $db, string $v, array $payload): void
    {
        $out = '';
        $common = ['v' => $v, 'now' => (string) $payload['now']];
        $k = \is_array($payload['k']) ? $payload['k'] : [];
        foreach ([
            'phase u, page 1' => [SimpleFeedRanker::unseenSql(false), $common + ['seed' => $payload['seed'], 'n' => 31]],
            'phase u, next page' => [SimpleFeedRanker::unseenSql(true), $common + ['seed' => $payload['seed'], 'n' => 31, 'k' => $k[0] ?? '1', 'kid' => $k[1] ?? '']],
            'phase s, next page' => [SimpleFeedRanker::seenSql(true), $common + ['n' => 31, 'k' => '2000-01-01T00:00:00.000000Z', 'kid' => '00000000-0000-0000-0000-000000000000']],
        ] as $name => [$sql, $params]) {
            $plan = $db->fetchFirstColumn('EXPLAIN (ANALYZE, COSTS OFF, TIMING ON) '.$sql, $params, ['n' => ParameterType::INTEGER]);
            $out .= "── {$name}\n".implode("\n", array_map('strval', $plan))."\n\n";
        }
        file_put_contents(static::getContainer()->getParameter('kernel.project_dir').'/var/w39-explain.txt', $out);
        self::assertSame(ForYouCursor::UNSEEN, $payload['p']);
    }
}
