<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use App\Entity\User;
use App\Tests\Functional\WaveApiTestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** W-27: user search (`GET /search?type=users`). */
final class UserSearchTest extends WaveApiTestCase
{
    public function testMatchingIsCaseAccentInsensitiveWithPrefixAndTrigram(): void
    {
        $me = $this->createUser('searcher');
        $this->createUser('queen_b', displayName: 'Beyoncé Knowles');
        $this->createUser('jean.dupont', displayName: 'J. Dupont');
        $this->createUser('jean', displayName: 'Jean');
        $this->createUser('unrelated', displayName: 'Nobody');

        self::assertSame(['queen_b'], $this->usernames($this->search($me, 'beyonce')));
        self::assertSame(['queen_b'], $this->usernames($this->search($me, 'knowles')), 'prefix of a later word');
        self::assertContains('jean.dupont', $this->usernames($this->search($me, 'JEAN')));
        self::assertContains('jean', $this->usernames($this->search($me, 'jeam')), 'trigram');
        self::assertSame([], $this->usernames($this->search($me, 'zzzz')));

        $body = $this->json($this->search($me, 'jean'));
        self::assertSame(['users'], array_keys($body));
        self::assertSame(['id', 'username', 'displayName', 'avatarUrl', 'followersCount', 'isMe', 'isFollowedByMe'], array_keys($body['users']['items'][0]));
    }

    public function testRankingExactThenPrefixThenSimilarityThenFollowers(): void
    {
        $me = $this->createUser('searcher');
        $this->createUser('marco', displayName: 'Marco');
        $this->createUser('marcopolo', displayName: 'Polo');
        $popular = $this->createUser('marcus', displayName: 'Marcus');
        $this->createUser('marcel', displayName: 'Marcel');
        $this->em()->getConnection()->executeStatement('UPDATE app_user SET followers_count = 50 WHERE id = ?', [$popular->getId()->toRfc4122()]);

        $names = $this->usernames($this->search($me, 'marco'));
        self::assertSame('marco', $names[0], 'exact username first');
        self::assertSame('marcopolo', $names[1], 'then prefix');
        self::assertSame(['marcus', 'marcel'], \array_slice($names, 2), 'then similarity, tie broken by followers');
    }

    public function testCallerAndSuspendedUsersAreExcludedAndFollowFlagIsBatched(): void
    {
        $me = $this->createUser('anna');
        $banned = $this->createUser('annabel');
        $banned->suspend();
        $this->em()->flush();
        $this->createUser('annie');
        $this->api('PUT', '/api/v1/users/annie/follow', $me);
        for ($i = 0; $i < 6; ++$i) {
            $this->createUser('annick'.$i);
        }

        $names = $this->usernames($this->search($me, 'ann'));
        self::assertNotContains('anna', $names);
        self::assertNotContains('annabel', $names);
        $annie = array_values(array_filter($this->json($this->search($me, 'annie'))['users']['items'], static fn (array $u): bool => 'annie' === $u['username']))[0];
        self::assertSame([true, 1, false], [$annie['isFollowedByMe'], $annie['followersCount'], $annie['isMe']]);

        self::assertSame(
            $this->countQueries('GET', '/api/v1/search?type=users&q=ann&limit=2', $me),
            $this->countQueries('GET', '/api/v1/search?type=users&q=ann&limit=6', $me),
        );
    }

    public function testValidationPaginationAndCap(): void
    {
        $me = $this->createUser('searcher');
        for ($i = 0; $i < 5; ++$i) {
            $this->createUser('paul'.$i);
        }

        $this->assertProblem($this->api('GET', '/api/v1/search?type=users&q=p', $me), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/search?q=paul', $me), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/search?type=artists&q=paul', $me), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/search?type=users&q='.str_repeat('a', 101), $me), 422, 'validation_failed');
        $this->assertProblem($this->api('GET', '/api/v1/search?type=users&q=paul'), 401, 'unauthenticated');

        $p1 = $this->json($this->search($me, 'paul', '&limit=3'));
        self::assertCount(3, $p1['users']['items']);
        $p2 = $this->json($this->search($me, 'paul', '&limit=3&cursor='.$p1['users']['nextCursor']));
        self::assertCount(2, $p2['users']['items']);
        self::assertNull($p2['users']['nextCursor']);
        self::assertSame([], array_intersect($this->usernames($this->search($me, 'paul', '&limit=3')), array_column($p2['users']['items'], 'username')));

        // A cursor is bound to its query and to the 200-result cap.
        $this->assertProblem($this->search($me, 'paulo', '&cursor='.$p1['users']['nextCursor']), 400, 'invalid_cursor');
        $codec = static::getContainer()->get(\App\Pagination\CursorCodec::class);
        $this->assertProblem($this->search($me, 'paul', '&cursor='.$codec->encode(['users', 'paul', 200])), 400, 'invalid_cursor');
    }

    public function testMetacharactersNeitherFailNorWiden(): void
    {
        $me = $this->createUser('searcher');
        $this->createUser('plain_user');
        foreach (['%%', '__', ':*', "a'b", 'a&b', '\\\\', '%a'] as $q) {
            $r = $this->search($me, $q);
            self::assertSame(200, $r->getStatusCode(), $q.': '.$r->getContent(false));
            self::assertNotContains('plain_user', $this->usernames($r), $q.' must not match everything');
        }
    }

    public function testRateLimited(): void
    {
        $me = $this->createUser('searcher');
        for ($i = 0; $i < 60; ++$i) {
            $this->search($me, 'xy');
        }
        $this->assertProblem($this->search($me, 'xy'), 429, 'rate_limited');
    }

    private function search(User $as, string $q, string $extra = ''): ResponseInterface
    {
        return $this->api('GET', '/api/v1/search?type=users&q='.rawurlencode($q).$extra, $as);
    }

    /** @return list<string> */
    private function usernames(ResponseInterface $r): array
    {
        self::assertSame(200, $r->getStatusCode(), $r->getContent(false));

        return array_column($this->json($r)['users']['items'], 'username');
    }
}
