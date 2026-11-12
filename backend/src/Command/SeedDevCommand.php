<?php

declare(strict_types=1);

namespace App\Command;

use App\Artist\SlugGenerator;
use App\Command\Seed\AudioSynth;
use App\Command\Seed\Catalog;
use App\Command\Seed\ImageSynth;
use App\Command\Seed\SeedRng;
use App\Entity\Artist;
use App\Entity\Genre;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\MediaStatus;
use App\Enum\Visibility;
use App\Enum\VisualType;
use App\Media\MessageHandler\GenerateClipHandler;
use App\Media\MessageHandler\ProcessImageHandler;
use App\Media\MessageHandler\ProcessPostVisualHandler;
use App\Message\GenerateClip;
use App\Message\ProcessImage;
use App\Message\ProcessPostVisual;
use App\Post\HashtagExtractor;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * DEV ONLY. Empties the application data (not `genre`, not the migrations table) and the MinIO objects,
 * then builds a fixed demo scenario (Catalog) through the real media handlers. Must run in the worker
 * container (the only one with ffmpeg). Refuses to run outside APP_ENV=dev.
 */
#[AsCommand(name: 'app:seed', description: 'DEV ONLY: reset the dev database and MinIO, then seed the demo scenario')]
final class SeedDevCommand
{
    private const int SEED = 1112;
    private const int BATCH = 8;
    private const int POOL = 7;

    private string $workDir = '';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ObjectStorage $storage,
        private readonly SlugGenerator $slugs,
        private readonly ProcessImageHandler $imageHandler,
        private readonly GenerateClipHandler $clipHandler,
        private readonly ProcessPostVisualHandler $visualHandler,
        #[Autowire(service: 'wave.storage.private')]
        private readonly FilesystemOperator $private,
        #[Autowire(service: 'wave.storage.public')]
        private readonly FilesystemOperator $public,
        #[Autowire('%kernel.environment%')]
        private readonly string $env,
    ) {
    }

    public function __invoke(OutputInterface $output): int
    {
        if ('dev' !== $this->env) {
            $output->writeln('<error>app:seed only runs with APP_ENV=dev (refused).</error>');

            return Command::FAILURE;
        }
        $started = microtime(true);
        $fs = new Filesystem();
        $base = (string) ($_SERVER['MEDIA_TMP_DIR'] ?? $_ENV['MEDIA_TMP_DIR'] ?? sys_get_temp_dir());
        $this->workDir = rtrim($base, '/').'/seed-'.getmypid();
        $fs->mkdir($this->workDir);
        $rng = new SeedRng(self::SEED);

        try {
            $log = static fn (string $m) => $output->writeln(\sprintf('[%5.1fs] %s', microtime(true) - $started, $m));

            $log('wipe database and storage');
            $this->wipe();

            $log('users and artists');
            $users = $this->createUsers();
            $artists = $this->createArtists($users);

            $log('avatars');
            $images = new ImageSynth('ffmpeg');
            $this->createAvatars($images, $users, $artists);

            $log('tracks (audio synthesis + real pipeline)');
            $tracks = $this->createTracks($artists, $images, $log);

            $log('social graph');
            $this->createFollows($users);
            $this->createPlaylists($users, $tracks, $rng);
            $this->createTrackLikesAndListens($users, $tracks, $rng);

            $log('posts');
            $posts = $this->createPosts($users, $tracks, $images);
            $this->createPostInteractions($users, $tracks, $posts, $rng);

            $log('timestamps and counters');
            $this->stagger($users, $tracks);
            $this->recount();
        } finally {
            $fs->remove($this->workDir);
        }

        $output->writeln(\sprintf('Seed done in %.0f s. Password for every account: %s', microtime(true) - $started, Catalog::PASSWORD));

        return Command::SUCCESS;
    }

    private function wipe(): void
    {
        $this->em->clear();
        $tables = $this->db->fetchFirstColumn("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename NOT IN ('genre', 'doctrine_migration_versions', 'lock_keys')");
        $this->db->executeStatement('TRUNCATE TABLE '.implode(', ', array_map(static fn (string $t): string => '"'.$t.'"', $tables)).' RESTART IDENTITY CASCADE');
        foreach ([$this->private, $this->public] as $fs) {
            foreach ($fs->listContents('', true) as $item) {
                if ($item->isFile()) {
                    $fs->delete($item->path());
                }
            }
        }
    }

    /** @return array<string, User> */
    private function createUsers(): array
    {
        $users = [];
        foreach (Catalog::users() as $key => [$display, $bio]) {
            $u = new User($key.'@wave.test', $key, $display);
            $u->setBio($bio);
            $u->setPassword($this->hasher->hashPassword($u, Catalog::PASSWORD));
            $u->markEmailVerified();
            $this->em->persist($u);
            $users[$key] = $u;
        }
        $this->em->flush();

        return $users;
    }

    /**
     * @param array<string, User> $users
     *
     * @return array<string, Artist>
     */
    private function createArtists(array $users): array
    {
        $artists = [];
        foreach (Catalog::artists() as $key => [$owner, $name, $bio]) {
            $a = new Artist($users[$owner], $this->slugs->generate($name), $name, $bio);
            $this->em->persist($a);
            $this->em->flush();
            $artists[$key] = $a;
        }

        return $artists;
    }

    /**
     * @param array<string, User>   $users
     * @param array<string, Artist> $artists
     */
    private function createAvatars(ImageSynth $images, array $users, array $artists): void
    {
        $i = 0;
        foreach (Catalog::users() as $key => [$display, , $has]) {
            ++$i;
            if ($has) {
                $this->image($images, ProcessImage::TARGET_USER_AVATAR, $users[$key]->getId(), 'avatars/users', $this->initials($display), $i, 'avatar');
            }
        }
        foreach (Catalog::artists() as $key => [, $name, , $has]) {
            ++$i;
            if ($has) {
                $this->image($images, ProcessImage::TARGET_ARTIST_AVATAR, $artists[$key]->getId(), 'avatars/artists', $this->initials($name), $i, 'avatar');
            }
        }
        $this->em->clear();
    }

    private function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/\s+/', trim($name)) ?: [] as $part) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return mb_substr($out, 0, 2);
    }

    /** @param 'user_avatar'|'artist_avatar'|'track_cover' $target */
    private function image(ImageSynth $images, string $target, Uuid $id, string $dir, string $label, int $seed, string $kind): void
    {
        $file = $this->workDir.'/img-'.$seed.'-'.$kind.'.png';
        match ($kind) {
            'cover' => $images->cover($label, $seed, $file),
            default => $images->avatar($label, $seed, $file),
        };
        $key = \sprintf('quarantine/%s/%s/%s', $dir, $id->toRfc4122(), Uuid::v7()->toRfc4122());
        $this->putFile($key, $file, 'image/png');
        ($this->imageHandler)(new ProcessImage($target, $id->toRfc4122(), $key));
        @unlink($file);
    }

    private function putFile(string $key, string $file, string $contentType): void
    {
        $stream = fopen($file, 'r');
        if (false === $stream) {
            throw new \RuntimeException('cannot read '.$file);
        }
        try {
            $this->storage->write(StorageArea::Private, $key, $stream, $contentType);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Synthesis in batches, then the REAL ProcessTrack handler in a pool of child processes
     * (loudnorm is single-threaded: sequentially it would take ~25 s per track).
     *
     * @param array<string, Artist> $artists
     *
     * @return array<int, string> track number => track id
     */
    private function createTracks(array $artists, ImageSynth $images, callable $log): array
    {
        $synth = new AudioSynth('ffmpeg');
        $ids = [];
        foreach (array_chunk(array_keys(Catalog::tracks()), self::BATCH) as $batch) {
            $dirs = [];
            $procs = [];
            foreach ($batch as $n) {
                $dirs[$n] = $this->workDir.'/t'.$n;
                new Filesystem()->mkdir($dirs[$n]);
                $procs[$n] = $synth->startSections($this->spec($n), $dirs[$n]);
            }
            $this->wait($procs);
            $procs = [];
            foreach ($batch as $n) {
                $procs[$n] = $synth->startSong($this->spec($n), $dirs[$n], $dirs[$n].'/song.mp3');
            }
            $this->wait($procs);

            foreach ($batch as $n) {
                [$artistKey, $title, $genre, , , $audience, $clips] = Catalog::tracks()[$n];
                $mp3 = $dirs[$n].'/song.mp3';
                $artist = $this->em->find(Artist::class, $artists[$artistKey]->getId());
                if (null === $artist) {
                    throw new \RuntimeException('artist vanished');
                }
                $genreRef = $this->em->getReference(Genre::class, $genre);
                if (null === $genreRef) {
                    throw new \RuntimeException('unknown genre '.$genre);
                }
                $track = new Track($artist, $genreRef, $title, (int) filesize($mp3), Visibility::from($audience), $clips);
                $rawKey = 'quarantine/tracks/'.$track->getId()->toRfc4122().'/source';
                $this->putFile($rawKey, $mp3, 'audio/mpeg');
                $track->setRawKey($rawKey);
                $this->em->persist($track);
                $this->em->flush();
                $ids[$n] = $track->getId()->toRfc4122();
                new Filesystem()->remove($dirs[$n]);
            }
            $this->em->clear();
        }
        $log(\sprintf('  %d raw tracks uploaded, processing', \count($ids)));

        $running = [];
        $queue = $ids;
        while ([] !== $queue || [] !== $running) {
            while ([] !== $queue && \count($running) < self::POOL) {
                $n = (int) array_key_first($queue);
                $p = new Process([\PHP_BINARY, 'bin/console', 'app:seed:process-track', $queue[$n], '--env=dev', '--no-interaction'], \dirname(__DIR__, 2), null, null, 600.0);
                $p->start();
                $running[$n] = $p;
                unset($queue[$n]);
            }
            foreach ($running as $n => $p) {
                if (!$p->isRunning()) {
                    if (!$p->isSuccessful()) {
                        throw new \RuntimeException(\sprintf('track %d processing failed: %s %s', $n, $p->getErrorOutput(), $p->getOutput()));
                    }
                    unset($running[$n]);
                }
            }
            usleep(100_000);
        }

        $i = 0;
        foreach ($ids as $n => $id) {
            $status = (string) $this->db->fetchOne('SELECT status FROM track WHERE id = :id', ['id' => $id]);
            if ('ready' !== $status) {
                throw new \RuntimeException(\sprintf('track %d did not reach ready (%s)', $n, $status));
            }
            if (Catalog::tracks()[$n][7]) {
                $this->image($images, ProcessImage::TARGET_TRACK_COVER, Uuid::fromString($id), 'covers', Catalog::tracks()[$n][1], 100 + $n, 'cover');
            }
            ++$i;
        }
        $this->em->clear();

        return $ids;
    }

    /** @return array{archetype: string, seed: int, duration: int} */
    private function spec(int $n): array
    {
        return ['archetype' => Catalog::tracks()[$n][3], 'seed' => $n * 7919 + self::SEED, 'duration' => Catalog::tracks()[$n][4]];
    }

    /** @param array<int, Process> $procs */
    private function wait(array $procs): void
    {
        foreach ($procs as $n => $p) {
            $p->wait();
            if (!$p->isSuccessful()) {
                throw new \RuntimeException(\sprintf('audio synthesis failed for track %d: %s', $n, $p->getErrorOutput()));
            }
        }
    }

    /** @param array<string, User> $users */
    private function createFollows(array $users): void
    {
        foreach (Catalog::follows() as $follower => $followed) {
            foreach ($followed as $f) {
                $this->db->executeStatement('INSERT INTO follow (follower_id, followed_id, created_at) VALUES (:a, :b, now() - make_interval(days => :d))', [
                    'a' => $this->uid($users, $follower), 'b' => $this->uid($users, $f), 'd' => 3 + \strlen($follower.$f) % 20,
                ]);
            }
        }
        foreach (Catalog::closeFriends() as $owner => $friends) {
            foreach ($friends as $f) {
                $this->db->executeStatement('INSERT INTO close_friend (owner_id, friend_id, created_at) VALUES (:a, :b, now() - interval \'2 days\')', [
                    'a' => $this->uid($users, $owner), 'b' => $this->uid($users, $f),
                ]);
            }
        }
    }

    /** @param array<string, User> $users */
    private function uid(array $users, string $key): string
    {
        return $users[$key]->getId()->toRfc4122();
    }

    /** ADR 012: may this user see (like, comment, listen to) a track of that owner? */
    private function canSee(string $viewer, string $owner, string $visibility): bool
    {
        if ($viewer === $owner) {
            return true;
        }

        return match ($visibility) {
            'public' => true,
            'followers' => \in_array($owner, Catalog::follows()[$viewer] ?? [], true),
            'close_friends' => \in_array($viewer, Catalog::closeFriends()[$owner] ?? [], true),
            default => false,
        };
    }

    private function trackOwner(int $n): string
    {
        return Catalog::artists()[Catalog::tracks()[$n][0]][0];
    }

    /**
     * @param array<string, User> $users
     * @param array<int, string>  $tracks
     */
    private function createPlaylists(array $users, array $tracks, SeedRng $rng): void
    {
        $ids = [];
        foreach (Catalog::playlists() as $i => [$owner, $name, $desc, $public, $nums]) {
            $id = Uuid::v7()->toRfc4122();
            $ids[$i] = $id;
            $this->db->executeStatement('INSERT INTO playlist (id, owner_id, name, description, is_public, created_at, updated_at) VALUES (:id, :o, :n, :d, :p, now() - make_interval(days => :ago), now() - make_interval(days => :ago))', [
                'id' => $id, 'o' => $this->uid($users, $owner), 'n' => $name, 'd' => $desc, 'p' => $public, 'ago' => 5 + $i,
            ], ['p' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
            foreach ($nums as $pos => $n) {
                $this->db->executeStatement('INSERT INTO playlist_item (id, playlist_id, track_id, position, added_at) VALUES (:id, :p, :t, :pos, now() - make_interval(days => :ago))', [
                    'id' => Uuid::v7()->toRfc4122(), 'p' => $id, 't' => $tracks[$n], 'pos' => $pos, 'ago' => 4 + $i,
                ]);
            }
        }
        foreach (Catalog::playlists() as $i => [$owner, , , $public]) {
            if (!$public) {
                continue;
            }
            foreach (array_keys(Catalog::users()) as $u) {
                if ($u !== $owner && $rng->float() < 0.35) {
                    $this->db->executeStatement('INSERT INTO playlist_like (user_id, playlist_id, created_at) VALUES (:u, :p, now() - make_interval(mins => :m))', [
                        'u' => $this->uid($users, $u), 'p' => $ids[$i], 'm' => $rng->between(30, 4000),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, User> $users
     * @param array<int, string>  $tracks
     */
    private function createTrackLikesAndListens(array $users, array $tracks, SeedRng $rng): void
    {
        foreach (array_keys(Catalog::users()) as $u) {
            foreach (Catalog::tracks() as $n => $t) {
                $owner = $this->trackOwner($n);
                if ($u === $owner || !$this->canSee($u, $owner, $t[5])) {
                    continue;
                }
                if ($rng->float() < ($t[7] ? 0.45 : 0.22)) {
                    $this->db->executeStatement('INSERT INTO track_like (user_id, track_id, created_at) VALUES (:u, :t, now() - make_interval(mins => :m))', [
                        'u' => $this->uid($users, $u), 't' => $tracks[$n], 'm' => $rng->between(30, 9000),
                    ]);
                }
                if ($rng->float() < 0.25) {
                    $ms = (int) ($t[4] * 1000 * (0.5 + $rng->float() * 0.5));
                    $this->db->executeStatement('INSERT INTO listen (id, user_id, track_id, post_id, source, listened_ms, created_at) VALUES (:id, :u, :t, NULL, :s, :ms, now() - make_interval(mins => :m))', [
                        'id' => Uuid::v7()->toRfc4122(), 'u' => $this->uid($users, $u), 't' => $tracks[$n], 's' => $rng->float() < 0.7 ? 'player' : 'feed', 'ms' => $ms, 'm' => $rng->between(10, 9000),
                    ]);
                }
            }
        }
    }

    /**
     * @param array<string, User> $users
     * @param array<int, string>  $tracks
     *
     * @return list<array{string, int, string}> [post id, track number, author key]
     */
    private function createPosts(array $users, array $tracks, ImageSynth $images): array
    {
        $posts = [];
        foreach (Catalog::posts() as $i => [$author, $n, $start, $secs, $caption, $visual, $hoursAgo]) {
            $track = $this->em->find(Track::class, $tracks[$n]);
            $user = $this->em->find(User::class, $users[$author]->getId());
            if (null === $track || null === $user) {
                throw new \RuntimeException('post references a missing track or user');
            }
            $post = new Post($user, $track, $start * 1000, $secs * 1000, $caption);
            $pid = $post->getId()->toRfc4122();
            if ('none' !== $visual) {
                $raw = $this->workDir.'/visual-'.$i.('video' === $visual ? '.mp4' : '.png');
                if ('video' === $visual) {
                    $images->postVideo(200 + $i, $raw);
                } else {
                    $images->postImage(mb_substr(Catalog::tracks()[$n][1], 0, 24), 200 + $i, $raw);
                }
                $key = 'quarantine/posts/'.$pid.'/visual';
                $this->putFile($key, $raw, 'video' === $visual ? 'video/mp4' : 'image/png');
                $post->attachRawVisual('video' === $visual ? VisualType::Video : VisualType::Image, $key);
                @unlink($raw);
            }
            $this->em->persist($post);
            $this->em->flush();
            $this->attachHashtags($pid, HashtagExtractor::extract($caption));
            ($this->clipHandler)(new GenerateClip($pid));
            if ('none' !== $visual) {
                ($this->visualHandler)(new ProcessPostVisual($pid));
            }
            $this->em->refresh($post);
            if (MediaStatus::Ready !== $post->getStatus()) {
                throw new \RuntimeException(\sprintf('post %d did not reach ready (%s)', $i, $post->getStatus()->value));
            }
            $this->db->executeStatement('UPDATE post SET created_at = now() - make_interval(hours => :h) WHERE id = :id', ['h' => $hoursAgo, 'id' => $pid]);
            $posts[] = [$pid, $n, $author];
        }
        $this->em->clear();

        return $posts;
    }

    /** @param list<string> $names */
    private function attachHashtags(string $postId, array $names): void
    {
        sort($names, \SORT_STRING);
        foreach ($names as $name) {
            $hid = $this->db->fetchOne('INSERT INTO hashtag (id, name, post_count, created_at) VALUES (:id, :n, 1, now()) ON CONFLICT (name) DO UPDATE SET post_count = hashtag.post_count + 1 RETURNING id', ['id' => Uuid::v7()->toRfc4122(), 'n' => $name]);
            $this->db->executeStatement('INSERT INTO post_hashtag (post_id, hashtag_id) VALUES (:p, :h)', ['p' => $postId, 'h' => $hid]);
        }
    }

    /**
     * @param array<string, User>              $users
     * @param array<int, string>               $tracks
     * @param list<array{string, int, string}> $posts
     */
    private function createPostInteractions(array $users, array $tracks, array $posts, SeedRng $rng): void
    {
        foreach ($posts as [$pid, $n, $author]) {
            $vis = Catalog::tracks()[$n][5];
            $owner = $this->trackOwner($n);
            $comments = 0;
            foreach (array_keys(Catalog::users()) as $u) {
                if ($u === $author || !$this->canSee($u, $owner, $vis)) {
                    continue;
                }
                $uid = $this->uid($users, $u);
                if ($rng->float() < 0.5) {
                    $this->db->executeStatement('INSERT INTO post_like (user_id, post_id, created_at) VALUES (:u, :p, now() - make_interval(mins => :m))', ['u' => $uid, 'p' => $pid, 'm' => $rng->between(5, 600)]);
                }
                if ($comments < 3 && $rng->float() < 0.3) {
                    ++$comments;
                    $this->db->executeStatement('INSERT INTO comment (id, post_id, author_id, body, created_at) VALUES (:id, :p, :u, :b, now() - make_interval(mins => :m))', [
                        'id' => Uuid::v7()->toRfc4122(), 'p' => $pid, 'u' => $uid, 'b' => Catalog::comments()[$rng->between(0, \count(Catalog::comments()) - 1)], 'm' => $rng->between(5, 500),
                    ]);
                }
                if ($rng->float() < 0.8) {
                    $clipMs = $this->clipMs($pid);
                    $watched = (int) ($clipMs * (0.3 + $rng->float() * 0.7));
                    $this->db->executeStatement('INSERT INTO post_view (user_id, post_id, watched_ms, completed, first_seen_at, last_seen_at) VALUES (:u, :p, :w, :c, now() - make_interval(mins => :m), now() - make_interval(mins => :m))', [
                        'u' => $uid, 'p' => $pid, 'w' => $watched, 'c' => $watched >= $clipMs * 0.9, 'm' => $rng->between(5, 400),
                    ], ['c' => \Doctrine\DBAL\ParameterType::BOOLEAN]);
                }
            }
        }
    }

    private function clipMs(string $pid): int
    {
        return (int) $this->db->fetchOne('SELECT clip_duration_ms FROM post WHERE id = :p', ['p' => $pid]);
    }

    /**
     * @param array<string, User> $users
     * @param array<int, string>  $tracks
     */
    private function stagger(array $users, array $tracks): void
    {
        $i = 0;
        foreach (array_keys(Catalog::users()) as $key) {
            $this->db->executeStatement('UPDATE app_user SET created_at = now() - make_interval(days => :d) WHERE id = :id', ['d' => 40 - $i * 3, 'id' => $this->uid($users, $key)]);
            $this->db->executeStatement('UPDATE artist SET created_at = now() - make_interval(days => :d) WHERE owner_id = :id', ['d' => 38 - $i * 3, 'id' => $this->uid($users, $key)]);
            ++$i;
        }
        foreach ($tracks as $n => $id) {
            $this->db->executeStatement('UPDATE track SET created_at = now() - make_interval(hours => :h), updated_at = now() - make_interval(hours => :h) WHERE id = :id', ['h' => (int) (700 - $n * 14), 'id' => $id]);
        }
    }

    private function recount(): void
    {
        foreach ([
            'UPDATE app_user u SET followers_count = (SELECT count(*) FROM follow WHERE followed_id = u.id), following_count = (SELECT count(*) FROM follow WHERE follower_id = u.id)',
            'UPDATE track t SET like_count = (SELECT count(*) FROM track_like WHERE track_id = t.id), play_count = (SELECT count(*) FROM listen WHERE track_id = t.id)',
            'UPDATE playlist p SET item_count = (SELECT count(*) FROM playlist_item WHERE playlist_id = p.id), like_count = (SELECT count(*) FROM playlist_like WHERE playlist_id = p.id)',
            'UPDATE post p SET like_count = (SELECT count(*) FROM post_like WHERE post_id = p.id), comment_count = (SELECT count(*) FROM comment WHERE post_id = p.id), view_count = (SELECT count(*) FROM post_view WHERE post_id = p.id)',
            'UPDATE hashtag h SET post_count = (SELECT count(*) FROM post_hashtag WHERE hashtag_id = h.id)',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
    }
}
