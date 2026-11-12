<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\Visibility;
use App\Post\HashtagExtractor;
use App\Repository\PostRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * W-37, phase 3 review: what the lots' own tests leave out. FB-18 outsiders (stranger,
 * ex-follower, follower outside close friends, removed close friend) on reports, post creation
 * and post routes; hidden/suspended content across every list; brigading then dismiss; stream
 * tokens of a viewer suspended meanwhile; hostile multipart and hashtag input; notification
 * loops and the scope of `read`.
 */
final class PhaseThreeReviewSecurityTest extends SecurityTestCase
{
    private const string UNKNOWN = '0192f5a4-6b1e-7cc0-9a5e-3f1a2b3c4d5e';

    /**
     * FB-18 (T7, T17): every outsider of a restricted track gets, on reports of the track, its
     * post and a comment of that post, on post creation, and on every post route, the exact
     * bytes (status, headers, body) of an unknown id of the same type. Nothing is written.
     */
    public function testRestrictedAudienceOutsidersGetTheUnknownIdAnswerEverywhere(): void
    {
        $o = $this->createUser('owner');
        $f = $this->createUser('follower');
        $c = $this->createUser('friend');
        $s = $this->createUser('stranger');
        $x = $this->createUser('exfollower');
        $r = $this->createUser('removedfriend');
        $artist = $this->createArtist($o);
        $forFollowers = $this->createTrack($artist, 'F', true, Visibility::Followers, true);
        $forFriends = $this->createTrack($artist, 'C', true, Visibility::CloseFriends, true);
        foreach (['follower', 'friend', 'exfollower', 'removedfriend'] as $i => $name) {
            $who = [$f, $c, $x, $r][$i];
            self::assertLessThan(300, $this->as($who, 'PUT', '/api/v1/users/owner/follow')->getStatusCode(), $name);
        }
        foreach (['friend', 'removedfriend'] as $name) {
            self::assertLessThan(300, $this->as($o, 'PUT', '/api/v1/me/close-friends/'.$name)->getStatusCode(), $name);
        }
        $postF = $this->post($o, $forFollowers);
        $postC = $this->post($o, $forFriends);
        $comment = $this->as($c, 'POST', '/api/v1/posts/'.$postC->getId().'/comments', ['body' => 'only friends see this']);
        self::assertSame(201, $comment->getStatusCode(), (string) $comment->getContent());
        $commentId = (string) $this->decode($comment)['id'];
        // Ex-follower and removed close friend lose access afterwards.
        self::assertLessThan(300, $this->as($x, 'DELETE', '/api/v1/users/owner/follow')->getStatusCode());
        self::assertLessThan(300, $this->as($o, 'DELETE', '/api/v1/me/close-friends/removedfriend')->getStatusCode());
        $reportsBefore = $this->rows('report');

        $cases = [
            'followers track' => [[$s, $x], $forFollowers, $postF, null],
            'close-friends track' => [[$s, $x, $f, $r], $forFriends, $postC, $commentId],
        ];
        foreach ($cases as $label => [$outsiders, $track, $post, $cid]) {
            foreach ($outsiders as $who) {
                $tag = $label.' / '.$who->getUsername();
                $targets = ['track' => $track->getId()->toRfc4122(), 'post' => $post->getId()->toRfc4122()] + (null === $cid ? [] : ['comment' => $cid]);
                foreach ($targets as $type => $id) {
                    $reference = self::fingerprint($this->as($who, 'POST', '/api/v1/reports', ['targetType' => $type, 'targetId' => self::UNKNOWN, 'reason' => 'spam']));
                    self::assertStringStartsWith('404', $reference);
                    self::assertSame($reference, self::fingerprint($this->as($who, 'POST', '/api/v1/reports', ['targetType' => $type, 'targetId' => $id, 'reason' => 'spam'])), "$tag report $type");
                }
                $reference = self::fingerprint($this->createPostAs($who, self::UNKNOWN));
                self::assertStringStartsWith('404', $reference);
                self::assertSame($reference, self::fingerprint($this->createPostAs($who, $track->getId()->toRfc4122())), "$tag createPost");

                foreach ([['GET', ''], ['GET', '/stream-url'], ['DELETE', ''], ['PUT', '/like'], ['DELETE', '/like'], ['GET', '/comments'], ['POST', '/comments']] as [$method, $suffix]) {
                    $body = 'POST' === $method ? ['body' => 'hi'] : null;
                    $reference = self::fingerprint($this->as($who, $method, '/api/v1/posts/'.self::UNKNOWN.$suffix, $body));
                    self::assertStringStartsWith('404', $reference);
                    self::assertSame($reference, self::fingerprint($this->as($who, $method, '/api/v1/posts/'.$post->getId().$suffix, $body)), "$tag $method $suffix");
                }
                if (null !== $cid) {
                    $reference = self::fingerprint($this->as($who, 'DELETE', '/api/v1/comments/'.self::UNKNOWN));
                    self::assertSame($reference, self::fingerprint($this->as($who, 'DELETE', '/api/v1/comments/'.$cid)), "$tag delete comment");
                }
                $views = $this->as($who, 'POST', '/api/v1/post-views', ['views' => [['postId' => $post->getId()->toRfc4122(), 'watchedMs' => 5_000]]]);
                self::assertSame(204, $views->getStatusCode(), "$tag post-views");
            }
        }
        self::assertSame($reportsBefore, $this->rows('report'), 'no report written');
        self::assertSame(2, $this->rows('post'), 'no post created or deleted');
        self::assertSame(0, $this->rows('post_view'), 'no view of an outsider recorded');
        self::assertSame(1, $this->rows('comment'), 'no comment written or deleted');
    }

    /**
     * T9, §9, §10: hidden posts, posts on a hidden track and posts of a suspended author are absent
     * from both feeds and from profile lists, and 404 by id; hidden comments and comments of a
     * suspended author are absent from the comment list and 404 on delete.
     */
    public function testHiddenAndSuspendedContentIsAbsentFromEveryListAndByIdForOthers(): void
    {
        $o = $this->createUser('owner');
        $d = $this->createUser('dave');
        $a = $this->createUser('amy');
        $v = $this->createUser('viewer');
        $artist = $this->createArtist($o);
        $track = $this->createTrack($artist, 'Open');
        $hiddenTrack = $this->createTrack($artist, 'Hidden track');
        $ok = $this->post($o, $track);
        $hidden = $this->post($o, $track);
        $onHiddenTrack = $this->post($o, $hiddenTrack);
        $bySuspended = $this->post($d, $track);
        foreach (['owner', 'dave'] as $name) {
            self::assertLessThan(300, $this->as($v, 'PUT', '/api/v1/users/'.$name.'/follow')->getStatusCode());
        }
        $visibleComment = $this->comment($ok, $a, 'fine');
        $hiddenComment = $this->comment($ok, $a, 'rude');
        $suspendedComment = $this->comment($ok, $d, 'by dave');
        $db = $this->em()->getConnection();
        $db->executeStatement("UPDATE post SET moderation = 'hidden' WHERE id = ?", [$hidden->getId()->toRfc4122()]);
        $db->executeStatement("UPDATE track SET moderation = 'hidden' WHERE id = ?", [$hiddenTrack->getId()->toRfc4122()]);
        $db->executeStatement("UPDATE comment SET moderation = 'hidden' WHERE id = ?", [$hiddenComment]);
        $db->executeStatement('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$d->getId()->toRfc4122()]);
        $this->em()->clear();

        $okId = $ok->getId()->toRfc4122();
        $ids = fn (string $uri): array => array_column($this->decode($this->as($v, 'GET', $uri))['items'], 'id');
        self::assertSame([$okId], $ids('/api/v1/feed/following'), 'following feed');
        self::assertSame([$okId], $ids('/api/v1/feed/for-you'), 'for-you feed');
        self::assertSame([$okId], $ids('/api/v1/users/owner/posts'), 'profile list');
        self::assertSame(404, $this->as($v, 'GET', '/api/v1/users/dave/posts')->getStatusCode(), 'suspended profile');
        self::assertSame([$visibleComment], $ids('/api/v1/posts/'.$okId.'/comments'), 'comment list');

        $reference = self::fingerprint($this->as($v, 'GET', '/api/v1/posts/'.self::UNKNOWN));
        foreach (['hidden' => $hidden, 'hidden track' => $onHiddenTrack, 'suspended author' => $bySuspended] as $case => $post) {
            self::assertSame($reference, self::fingerprint($this->as($v, 'GET', '/api/v1/posts/'.$post->getId())), $case);
        }
        $reference = self::fingerprint($this->as($v, 'DELETE', '/api/v1/comments/'.self::UNKNOWN));
        foreach ([$hiddenComment, $suspendedComment] as $cid) {
            self::assertSame($reference, self::fingerprint($this->as($v, 'DELETE', '/api/v1/comments/'.$cid)));
        }
        // The owner still sees their own hidden post in their list (§9).
        self::assertContains($hidden->getId()->toRfc4122(), array_column($this->decode($this->as($o, 'GET', '/api/v1/users/owner/posts'))['items'], 'id'));
    }

    /**
     * Brigading (T18): the same reporter twice → 409; five sock puppets hide the post at once and
     * a clip URL issued before stops working (T9); a moderator `dismiss` restores it, and the same
     * puppets cannot hide it again (409). Comments: hide and restore move `comment_count` once.
     */
    public function testBrigadingHidesThenDismissRestoresAndPuppetsCannotReHide(): void
    {
        $o = $this->createUser('owner');
        $v = $this->createUser('viewer');
        $mod = $this->createUser('mod');
        $mod->setRoles(['ROLE_MODERATOR']);
        $this->em()->flush();
        $post = $this->post($o, $this->createTrack($this->createArtist($o)));
        $id = $post->getId()->toRfc4122();
        $commentId = $this->comment($post, $o, 'target');

        $clip = $this->as($v, 'GET', '/api/v1/posts/'.$id.'/stream-url');
        self::assertSame(200, $clip->getStatusCode(), (string) $clip->getContent());
        $clipPath = (string) parse_url((string) $this->decode($clip)['url'], \PHP_URL_PATH);
        self::assertSame(200, $this->raw('GET', $clipPath)->getStatusCode());

        self::assertSame(201, $this->report($v, 'post', $id)->getStatusCode());
        $dup = $this->report($v, 'post', $id);
        self::assertSame(409, $dup->getStatusCode());
        self::assertSame('already_reported', self::problemCode($dup));

        $puppets = [];
        for ($i = 0; $i < 4; ++$i) {
            $puppets[] = $this->createUser('puppet'.$i);
        }
        // Comment first: once the post is hidden its comments are out of reach (404), as they should.
        self::assertSame(201, $this->report($v, 'comment', $commentId)->getStatusCode());
        foreach ($puppets as $p) {
            self::assertSame(201, $this->report($p, 'comment', $commentId)->getStatusCode());
            self::assertSame(201, $this->report($p, 'post', $id)->getStatusCode());
        }
        $db = $this->em()->getConnection();
        self::assertSame('hidden', $db->fetchOne('SELECT moderation FROM post WHERE id = ?', [$id]));
        self::assertSame('hidden', $db->fetchOne('SELECT moderation FROM comment WHERE id = ?', [$commentId]));
        self::assertSame(0, (int) $db->fetchOne('SELECT comment_count FROM post WHERE id = ?', [$id]));
        self::assertSame(404, $this->raw('GET', $clipPath)->getStatusCode(), 'clip URL issued before hiding');
        self::assertSame(404, $this->as($v, 'GET', '/api/v1/posts/'.$id)->getStatusCode());

        foreach (['comment' => $commentId, 'post' => $id] as $type => $target) {
            $r = $this->as($mod, 'POST', '/api/v1/admin/report-targets/'.$type.'/'.$target.'/decision', ['action' => 'dismiss']);
            self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
        }
        self::assertSame('visible', $db->fetchOne('SELECT moderation FROM post WHERE id = ?', [$id]));
        self::assertSame(1, (int) $db->fetchOne('SELECT comment_count FROM post WHERE id = ?', [$id]));
        self::assertSame(200, $this->as($v, 'GET', '/api/v1/posts/'.$id)->getStatusCode());
        foreach ([...$puppets, $v] as $p) {
            self::assertSame(409, $this->report($p, 'post', $id)->getStatusCode(), 'puppets cannot re-report after dismiss');
        }
        self::assertSame('visible', $db->fetchOne('SELECT moderation FROM post WHERE id = ?', [$id]));
    }

    /**
     * qa phase 3 v3 low 4: stream tokens carry no session, so they must die with the viewer's
     * account. A track token and a clip token issued before the suspension answer 404 after it.
     */
    public function testStreamTokensOfAViewerSuspendedMeanwhileStopWorking(): void
    {
        $o = $this->createUser('owner');
        $v = $this->createUser('viewer');
        $track = $this->createTrack($this->createArtist($o));
        $post = $this->post($o, $track);
        $paths = [];
        foreach (['/api/v1/tracks/'.$track->getId().'/stream-url', '/api/v1/posts/'.$post->getId().'/stream-url'] as $uri) {
            $r = $this->as($v, 'GET', $uri);
            self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());
            $paths[] = (string) parse_url((string) $this->decode($r)['url'], \PHP_URL_PATH);
        }
        foreach ($paths as $path) {
            self::assertSame(200, $this->raw('GET', $path)->getStatusCode());
        }
        $this->em()->getConnection()->executeStatement('UPDATE app_user SET suspended_at = now() WHERE id = ?', [$v->getId()->toRfc4122()]);
        $this->em()->clear();
        foreach ($paths as $path) {
            foreach (['GET', 'HEAD'] as $method) {
                self::assertSame(404, $this->raw($method, $path)->getStatusCode(), "$method $path");
            }
        }
    }

    /**
     * T8, T21 on the multipart route: unknown, malformed or non-UTF-8 field names and values are
     * a 422 problem (never a 500), and nothing is written.
     */
    public function testHostileMultipartFieldsAreRejectedCleanly(): void
    {
        $o = $this->createUser('owner');
        $trackId = $this->createTrack($this->createArtist($o))->getId()->toRfc4122();
        $valid = ['trackId' => $trackId, 'clipStartMs' => '0', 'clipDurationMs' => '30000'];
        $cases = [
            'unknown field' => $valid + ['authorId' => self::UNKNOWN],
            'mass assignment' => $valid + ['moderation' => 'visible'],
            'array value' => ['trackId' => [$trackId], 'clipStartMs' => '0', 'clipDurationMs' => '30000'],
            'NUL in caption' => $valid + ['caption' => "a\0b"],
            'invalid UTF-8 caption' => $valid + ['caption' => "a\xC3\x28"],
            'NUL in field name' => $valid + ["cap\0tion" => 'x'],
            'negative start' => ['trackId' => $trackId, 'clipStartMs' => '-1', 'clipDurationMs' => '30000'],
            'exponent' => ['trackId' => $trackId, 'clipStartMs' => '1e3', 'clipDurationMs' => '30000'],
        ];
        foreach ($cases as $case => $fields) {
            $r = $this->multipart($o, $fields);
            self::assertSame(422, $r->getStatusCode(), $case.': '.$r->getContent());
            self::assertSame('validation_failed', self::problemCode($r), $case);
        }
        self::assertSame(0, $this->rows('post'));
        self::assertSame(0, $this->rows('hashtag'));
    }

    /**
     * F19 (W-37 low, fixed): a multipart field name that is not valid UTF-8 was echoed in the 422
     * problem (CreatePostProcessor::readFields), JsonResponse could not encode it and the
     * exception listener itself threw: a 500 instead of a 422. No state is written.
     */
    public function testInvalidUtf8MultipartFieldNameIsA422NotA500(): void
    {
        $o = $this->createUser('owner');
        $trackId = $this->createTrack($this->createArtist($o))->getId()->toRfc4122();
        $this->client->getKernelBrowser()->catchExceptions(true);
        try {
            $r = $this->multipart($o, ['trackId' => $trackId, 'clipStartMs' => '0', 'clipDurationMs' => '30000', "\xFF\xFE" => 'x']);
            self::assertSame(422, $r->getStatusCode());
        } catch (\InvalidArgumentException $e) {
            self::fail('500: the problem listener throws ('.$e->getMessage().')');
        }
        self::assertSame(0, $this->rows('post'));
    }

    /**
     * T21: hashtags never contain invisible or direction-changing characters (zero-width, bidi
     * controls, BOM, soft hyphen), compatibility forms fold (fullwidth), names stay ≤ 50 code points.
     */
    public function testHashtagExtractionIgnoresInvisibleAndBidiCharacters(): void
    {
        $cases = [
            "#lo\u{200B}fi" => ['lo'],
            "#lo\u{200D}fi #lo\u{2060}fi" => ['lo'],
            "#\u{202E}ifol" => [],
            "#rap\u{202E}\u{2066}x" => ['rap'],
            "#\u{FEFF}bom #soft\u{00AD}hyphen" => ['soft'],
            '#ＬＯＦＩ #lofi' => ['lofi'],
            '#Lo_Fi #lofi #LOFI' => ['lo_fi', 'lofi'],
            '#'.str_repeat('a', 60) => [str_repeat('a', 50)],
            '#مرحبا #שלום' => ['مرحبا', 'שלום'],
            '##double #' => ['double'],
        ];
        foreach ($cases as $caption => $expected) {
            $names = HashtagExtractor::extract($caption);
            self::assertSame($expected, $names, json_encode($caption, \JSON_UNESCAPED_UNICODE) ?: 'caption');
            foreach ($names as $name) {
                self::assertSame(0, preg_match('/[\p{Cf}\p{Cc}\p{Zs}]/u', $name), $name);
                self::assertLessThanOrEqual(50, mb_strlen($name));
            }
        }
    }

    /**
     * §8b (T17): like/unlike and follow/unfollow loops leave at most one unread item with one
     * actor per read; `read` only touches the caller's rows and refuses any other field.
     */
    public function testNotificationLoopsStayOneItemAndReadIsScopedToTheCaller(): void
    {
        $o = $this->createUser('owner');
        $b = $this->createUser('bob');
        $c = $this->createUser('carol');
        $post = $this->post($o, $this->createTrack($this->createArtist($o)));
        $postUri = '/api/v1/posts/'.$post->getId().'/like';
        for ($i = 0; $i < 4; ++$i) {
            self::assertSame(200, $this->as($b, 'PUT', $postUri)->getStatusCode());
            self::assertSame(200, $this->as($b, 'DELETE', $postUri)->getStatusCode());
            self::assertLessThan(300, $this->as($b, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
            self::assertLessThan(300, $this->as($b, 'DELETE', '/api/v1/users/owner/follow')->getStatusCode());
        }
        self::assertSame(0, $this->unread($o), 'loops ending with an undo leave nothing');
        self::assertSame(200, $this->as($b, 'PUT', $postUri)->getStatusCode());
        self::assertLessThan(300, $this->as($b, 'PUT', '/api/v1/users/owner/follow')->getStatusCode());
        self::assertSame(2, $this->unread($o));
        self::assertSame(2, $this->rows('notification'));
        self::assertSame(2, $this->rows('notification_actor'));

        // Carol cannot read Owner's items, whatever she sends.
        $future = '2999-01-01T00:00:00Z';
        self::assertSame(204, $this->as($c, 'POST', '/api/v1/me/notifications/read', ['upTo' => $future])->getStatusCode());
        foreach ([['upTo' => $future, 'recipientId' => $o->getId()->toRfc4122()], ['upTo' => $future, 'ids' => [self::UNKNOWN]], ['upTo' => [$future]], ['upTo' => "2026-01-01T00:00:00Z\0"]] as $i => $body) {
            $r = $this->as($c, 'POST', '/api/v1/me/notifications/read', $body);
            self::assertSame(422, $r->getStatusCode(), $i.': '.$r->getContent());
        }
        self::assertSame(2, $this->unread($o));
        self::assertSame(404, $this->as($c, 'GET', '/api/v1/users/owner/notifications')->getStatusCode());
        self::assertSame(404, $this->as($c, 'GET', '/api/v1/me/notifications/'.self::UNKNOWN)->getStatusCode());
    }

    // ------------------------------------------------------------- helpers ---

    private function unread(User $user): int
    {
        $r = $this->as($user, 'GET', '/api/v1/me/notifications/unread-count');
        self::assertSame(200, $r->getStatusCode(), (string) $r->getContent());

        return (int) $this->decode($r)['unreadCount'];
    }

    private function report(User $as, string $type, string $id): Response
    {
        return $this->as($as, 'POST', '/api/v1/reports', ['targetType' => $type, 'targetId' => $id, 'reason' => 'spam']);
    }

    private function comment(Post $post, User $author, string $body): string
    {
        $id = Uuid::v7()->toRfc4122();
        $db = $this->em()->getConnection();
        $db->executeStatement("INSERT INTO comment (id, post_id, author_id, body, moderation, created_at) VALUES (?, ?, ?, ?, 'visible', clock_timestamp())", [$id, $post->getId()->toRfc4122(), $author->getId()->toRfc4122(), $body]);
        $db->executeStatement('UPDATE post SET comment_count = comment_count + 1 WHERE id = ?', [$post->getId()->toRfc4122()]);

        return $id;
    }

    private function rows(string $table): int
    {
        return (int) $this->em()->getConnection()->fetchOne("SELECT count(*) FROM {$table}");
    }

    private function post(User $author, Track $track): Post
    {
        $post = new Post($this->managed($author), $this->managed($track), 0, 30_000, null);
        $this->em()->persist($post);
        $post->setClipKey('audio/clips/'.$post->getId().'.m4a');
        $this->em()->flush();
        self::assertTrue(static::getContainer()->get(PostRepository::class)->tryMarkReady($post->getId()));
        $this->em()->clear();

        return $post;
    }

    private function createPostAs(User $user, string $trackId): Response
    {
        return $this->multipart($user, ['trackId' => $trackId, 'clipStartMs' => '0', 'clipDurationMs' => '30000']);
    }

    /** @param array<string, mixed> $fields */
    private function multipart(User $user, array $fields): Response
    {
        $browser = $this->client->getKernelBrowser();
        $browser->request('POST', '/api/v1/posts', $fields, [], [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user),
            'CONTENT_TYPE' => 'multipart/form-data',
        ]);

        return $browser->getResponse();
    }

    /** @return array<string, mixed> */
    private function decode(Response $r): array
    {
        return json_decode((string) $r->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed>|null $body */
    private function as(User $user, string $method, string $uri, ?array $body = null): Response
    {
        return $this->raw($method, $uri, null === $body ? null : (string) json_encode($body), ['HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($user)]);
    }

    /** Status, body and headers, minus what legitimately varies between two requests. */
    private static function fingerprint(Response $r): string
    {
        $headers = $r->headers->all();
        unset($headers['date'], $headers['x-debug-token'], $headers['x-debug-token-link']);
        ksort($headers);

        return $r->getStatusCode()."\n".json_encode($headers)."\n".$r->getContent();
    }
}
