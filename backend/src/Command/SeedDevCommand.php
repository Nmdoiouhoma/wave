<?php

declare(strict_types=1);

namespace App\Command;

use App\Artist\SlugGenerator;
use App\Command\Seed\AudioSynth;
use App\Command\Seed\Catalog;
use App\Command\Seed\ImageSynth;
use App\Command\Seed\SeedJournal;
use App\Command\Seed\SeedRng;
use App\Entity\Artist;
use App\Entity\Comment;
use App\Entity\Genre;
use App\Entity\Post;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\ListenSource;
use App\Enum\NotificationType;
use App\Enum\Visibility;
use App\Enum\VisualType;
use App\Listen\ListenRecorder;
use App\Media\MessageHandler\ProcessImageHandler;
use App\Message\ProcessImage;
use App\Post\HashtagExtractor;
use App\Recommendation\AffinityRecomputer;
use App\Recommendation\TrendingRecomputer;
use App\Repository\CloseFriendRepository;
use App\Repository\CommentRepository;
use App\Repository\FollowRepository;
use App\Repository\LikeRepository;
use App\Repository\ListenGrantRepository;
use App\Repository\NotificationRepository;
use App\Repository\PostViewRepository;
use App\Repository\RepostRepository;
use App\Storage\ObjectStorage;
use App\Storage\StorageArea;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Uid\Uuid;

/**
 * DEV ONLY (W-42). Builds the demo scenario (Catalog: hand-written part + part generated from fixed seeds)
 * through the real media handlers and the domain repositories (follow, like, comment, repost, listen, view:
 * counters and rules included). Must run in the worker container (the only one with ffmpeg). Refuses to run
 * outside APP_ENV=dev.
 *
 * Default: removes only the previous demo accounts (`*@wave.test`) and their content, real accounts stay.
 * `--purge`: empties every application table (not `genre`, not the migrations table) and both buckets.
 */
#[AsCommand(name: 'app:seed', description: 'DEV ONLY: (re)build the demo data; --purge also wipes real accounts')]
final class SeedDevCommand
{
    private const int SEED = 1112;
    private const int POOL = 8;
    /** Post jobs run php + a video encode each: kept low to stay under the worker's 1 GB cgroup limit. */
    private const int POST_POOL = 4;
    private const int BATCH = 8;
    private const string DEMO_DOMAIN = '@wave.test';

    private string $workDir = '';
    /** @var array<string, array<string, true>> viewer key => keys of the accounts he follows */
    private array $following = [];
    /** @var array<string, array<string, true>> owner key => keys of his close friends */
    private array $friends = [];
    /** @var array<string, User> */
    private array $users = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $db,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly ObjectStorage $storage,
        private readonly SlugGenerator $slugs,
        private readonly ProcessImageHandler $imageHandler,
        private readonly FollowRepository $follows,
        private readonly CommentRepository $comments,
        private readonly CloseFriendRepository $closeFriends,
        private readonly LikeRepository $likes,
        private readonly RepostRepository $reposts,
        private readonly NotificationRepository $notifications,
        private readonly PostViewRepository $views,
        private readonly ListenGrantRepository $grants,
        private readonly ListenRecorder $listens,
        private readonly AffinityRecomputer $affinity,
        private readonly TrendingRecomputer $trending,
        private readonly SeedJournal $journal,
        #[Autowire(service: 'wave.storage.private')]
        private readonly FilesystemOperator $private,
        #[Autowire(service: 'wave.storage.public')]
        private readonly FilesystemOperator $public,
        #[Autowire('%kernel.environment%')]
        private readonly string $env,
    ) {
    }

    public function __invoke(OutputInterface $output, #[Option(description: 'Also wipe real (non @wave.test) accounts, every table and both buckets')] bool $purge = false): int
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

            $log($purge ? 'PURGE: wipe database and storage' : 'remove previous demo accounts and content');
            $purge ? $this->wipe() : $this->removeDemo();

            $log('users and artists');
            $this->users = $this->createUsers();
            $artists = $this->createArtists($this->users);

            $log('avatars');
            $images = new ImageSynth('ffmpeg');
            $this->createAvatars($images, $this->users, $artists);

            $log('tracks (audio synthesis + real pipeline)');
            $tracks = $this->createTracks($artists, $images, $log);

            $log('social graph (follow, close friends)');
            $this->createFollows($rng);

            $log('posts (clips and visuals)');
            $posts = $this->createPosts($this->users, $tracks, $images, $log);

            $log('likes, listens, playlists');
            $this->createTrackLikesAndListens($tracks, $rng);
            $this->createPlaylists($tracks, $rng);

            $log('post interactions (likes, comments, views, feed listens, reposts)');
            $this->createPostInteractions($tracks, $posts, $rng);

            $log('timestamps and checks');
            $this->stagger($tracks);
            $this->journal->backfill('%'.self::DEMO_DOMAIN);
            $this->recountDerived();
            $this->checkCounters();

            $log('affinity and trending recompute');
            $this->recompute();
        } finally {
            $fs->remove($this->workDir);
        }

        $output->writeln(\sprintf('Seed done in %.0f s: %s. Password for every account: %s', microtime(true) - $started, $this->summary(), Catalog::PASSWORD));

        return Command::SUCCESS;
    }

    private function summary(): string
    {
        $parts = [];
        foreach (['users' => 'app_user', 'artists' => 'artist', 'tracks' => 'track', 'posts' => 'post', 'follows' => 'follow', 'track likes' => 'track_like', 'post likes' => 'post_like', 'listens' => 'listen', 'comments' => 'comment', 'reposts' => 'repost'] as $label => $table) {
            $parts[] = $this->db->fetchOne('SELECT count(*) FROM '.$table).' '.$label;
        }

        return implode(', ', $parts);
    }

    /** Removes the demo accounts and everything hanging from them (tracks first: track -> artist is RESTRICT) and their objects. */
    private function removeDemo(): void
    {
        $this->em->clear();
        $like = '%'.self::DEMO_DOMAIN;
        $ids = $this->db->fetchFirstColumn(
            'SELECT id FROM app_user WHERE email LIKE :l UNION SELECT a.id FROM artist a JOIN app_user u ON u.id = a.owner_id WHERE u.email LIKE :l'
            .' UNION SELECT t.id FROM track t JOIN artist a ON a.id = t.artist_id JOIN app_user u ON u.id = a.owner_id WHERE u.email LIKE :l'
            .' UNION SELECT p.id FROM post p JOIN app_user u ON u.id = p.author_id WHERE u.email LIKE :l'
            .' UNION SELECT p.id FROM post p JOIN track t ON t.id = p.track_id JOIN artist a ON a.id = t.artist_id JOIN app_user u ON u.id = a.owner_id WHERE u.email LIKE :l',
            ['l' => $like],
        );
        $this->journal->forgetDemo($like);
        $this->db->executeStatement('DELETE FROM track WHERE artist_id IN (SELECT a.id FROM artist a JOIN app_user u ON u.id = a.owner_id WHERE u.email LIKE :l)', ['l' => $like]);
        $this->db->executeStatement('DELETE FROM app_user WHERE email LIKE :l', ['l' => $like]);
        $this->db->executeStatement('DELETE FROM hashtag WHERE NOT EXISTS (SELECT 1 FROM post_hashtag WHERE hashtag_id = hashtag.id)');
        $this->recountSurvivors();
        if ([] === $ids) {
            return;
        }
        $pattern = '/'.implode('|', array_map(static fn (mixed $i): string => preg_quote((string) $i, '/'), $ids)).'/';
        foreach ([$this->private, $this->public] as $fs) {
            foreach ($fs->listContents('', true) as $item) {
                if ($item->isFile() && 1 === preg_match($pattern, $item->path())) {
                    $fs->delete($item->path());
                }
            }
        }
    }

    /**
     * The cascaded deletes above bypass the services, so the real accounts kept by a non-purge run
     * (a follow of a demo account, a demo like on a real post…) would keep stale counters.
     */
    private function recountSurvivors(): void
    {
        foreach ([
            'UPDATE app_user u SET followers_count = c.fr, following_count = c.fg FROM (SELECT id, (SELECT count(*) FROM follow WHERE followed_id = x.id) AS fr, (SELECT count(*) FROM follow WHERE follower_id = x.id) AS fg FROM app_user x) c WHERE c.id = u.id AND (u.followers_count <> c.fr OR u.following_count <> c.fg)',
            'UPDATE track t SET like_count = c.l, play_count = c.p FROM (SELECT id, (SELECT count(*) FROM track_like WHERE track_id = x.id) AS l, (SELECT count(*) FROM listen WHERE track_id = x.id) AS p FROM track x) c WHERE c.id = t.id AND (t.like_count <> c.l OR t.play_count <> c.p)',
            'UPDATE post p SET like_count = c.l, comment_count = c.c, view_count = c.v, repost_count = c.r FROM (SELECT id, (SELECT count(*) FROM post_like WHERE post_id = x.id) AS l, (SELECT count(*) FROM comment WHERE post_id = x.id) AS c, (SELECT count(*) FROM post_view WHERE post_id = x.id) AS v, (SELECT count(*) FROM repost WHERE post_id = x.id) AS r FROM post x) c WHERE c.id = p.id AND (p.like_count <> c.l OR p.comment_count <> c.c OR p.view_count <> c.v OR p.repost_count <> c.r)',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
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
                $p = new Process([\PHP_BINARY, 'bin/console', 'app:seed:process-track', $queue[$n], '--env=dev', '--no-debug', '--no-interaction'], \dirname(__DIR__, 2), null, null, 600.0);
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

    // ---------------------------------------------------------------- social graph

    /** Hand-written scenario first (exact graph, see docs/handoff/seed-1112.md), then generated follows. */
    private function createFollows(SeedRng $rng): void
    {
        foreach (Catalog::follows() as $follower => $followed) {
            foreach ($followed as $f) {
                $this->follow($follower, $f);
            }
        }
        $keys = array_keys(Catalog::users());
        // Candidates ordered by popularity (hand accounts first); hugo stays followed by nobody (scenario).
        $candidates = array_values(array_filter($keys, static fn (string $k): bool => 'hugo' !== $k));
        $handCount = \count(Catalog::follows());
        foreach (\array_slice($keys, $handCount) as $follower) {
            $n = $rng->between(5, 13);
            for ($tries = 0; $tries < 60 && \count($this->following[$follower] ?? []) < $n; ++$tries) {
                $target = $candidates[(int) (($rng->float() ** 1.6) * \count($candidates))];
                if ($target !== $follower) {
                    $this->follow($follower, $target);
                }
            }
        }
        // Close friends: hand lists, then 10 generated accounts pick 1-3 of their followers.
        foreach (Catalog::closeFriends() as $owner => $friends) {
            foreach ($friends as $f) {
                $this->addFriend($owner, $f);
            }
        }
        foreach (\array_slice($keys, $handCount, 10) as $owner) {
            $followers = array_keys(array_filter($this->following, static fn (array $set): bool => isset($set[$owner])));
            sort($followers);
            for ($i = 0, $n = min($rng->between(1, 3), \count($followers)); $i < $n; ++$i) {
                $this->addFriend($owner, $followers[$rng->between(0, \count($followers) - 1)]);
            }
        }
    }

    private function follow(string $follower, string $followed): void
    {
        $this->em->wrapInTransaction(fn (): int => $this->follows->follow($this->users[$follower], $this->users[$followed]));
        $this->following[$follower][$followed] = true;
        $this->db->executeStatement(
            'UPDATE follow SET created_at = now() - make_interval(hours => :h) WHERE follower_id = :a AND followed_id = :b',
            ['h' => 24 + (\strlen($follower.$followed) * 37) % 240, 'a' => $this->uid($follower), 'b' => $this->uid($followed)],
        );
    }

    private function addFriend(string $owner, string $friend): void
    {
        if ($this->closeFriends->add($this->users[$owner], $this->users[$friend])) {
            $this->friends[$owner][$friend] = true;
        }
    }

    private function uid(string $key): string
    {
        return $this->users[$key]->getId()->toRfc4122();
    }

    /** ADR 012: may this user see (like, comment, listen to) a track of that owner? */
    private function canSee(string $viewer, string $owner, string $visibility): bool
    {
        if ($viewer === $owner) {
            return true;
        }

        return match ($visibility) {
            'public' => true,
            'followers' => isset($this->following[$viewer][$owner]),
            'close_friends' => isset($this->friends[$owner][$viewer]),
            default => false,
        };
    }

    private function trackOwner(int $n): string
    {
        return Catalog::artists()[Catalog::tracks()[$n][0]][0];
    }

    /** @var array<string, list<string>> */
    private array $taste = [];

    /** Three favourite genres per account (fixed by the seed): likes and listens lean towards them. */
    /** @return list<string> */
    private function tasteOf(string $user, SeedRng $rng): array
    {
        if (!isset($this->taste[$user])) {
            $genres = array_values(array_unique(array_map(static fn (array $t): string => $t[2], Catalog::tracks())));
            sort($genres);
            $this->taste[$user] = [];
            for ($i = 0; $i < 3; ++$i) {
                $this->taste[$user][] = $genres[$rng->between(0, \count($genres) - 1)];
            }
        }

        return $this->taste[$user];
    }

    // ---------------------------------------------------------------- playlists (SQL, counters recounted)

    /** @param array<int, string> $tracks */
    private function createPlaylists(array $tracks, SeedRng $rng): void
    {
        $lists = Catalog::playlists();
        $keys = array_keys(Catalog::users());
        $public = array_values(array_filter(array_keys(Catalog::tracks()), fn (int $n): bool => 'public' === Catalog::tracks()[$n][5]));
        // 28 generated playlists of public tracks, owned by generated accounts.
        foreach (\array_slice($keys, 10) as $i => $owner) {
            for ($k = 0; $k < ($i < 6 ? 2 : 1); ++$k) {
                $genre = Catalog::tracks()[$public[$rng->between(0, \count($public) - 1)]][2];
                $nums = array_values(array_filter($public, static fn (int $n): bool => Catalog::tracks()[$n][2] === $genre));
                $nums = \array_slice($nums, 0, $rng->between(3, 7));
                if (\count($nums) >= 2) {
                    $lists[] = [$owner, 'Sélection '.$genre.' '.($k + 1), 'Mes morceaux '.$genre.' du moment.', 0 === $rng->between(0, 4) ? false : true, $nums];
                }
            }
        }
        $ids = [];
        foreach ($lists as $i => [$owner, $name, $desc, $isPublic, $nums]) {
            $id = Uuid::v7()->toRfc4122();
            $ids[$i] = $id;
            $this->db->executeStatement('INSERT INTO playlist (id, owner_id, name, description, is_public, created_at, updated_at) VALUES (:id, :o, :n, :d, :p, now() - make_interval(days => :ago), now() - make_interval(days => :ago))', [
                'id' => $id, 'o' => $this->uid($owner), 'n' => $name, 'd' => $desc, 'p' => $isPublic, 'ago' => 5 + $i % 9,
            ], ['p' => ParameterType::BOOLEAN]);
            foreach ($nums as $pos => $n) {
                $this->db->executeStatement('INSERT INTO playlist_item (id, playlist_id, track_id, position, added_at) VALUES (:id, :p, :t, :pos, now() - make_interval(days => :ago))', [
                    'id' => Uuid::v7()->toRfc4122(), 'p' => $id, 't' => $tracks[$n], 'pos' => $pos, 'ago' => 4 + $i % 9,
                ]);
            }
        }
        foreach ($lists as $i => [$owner, , , $isPublic]) {
            if (!$isPublic) {
                continue;
            }
            foreach ($keys as $u) {
                if ($u !== $owner && $rng->float() < 0.2) {
                    $this->em->wrapInTransaction(fn (): array => $this->likes->like('playlist', $this->users[$u], Uuid::fromString($ids[$i])));
                    $this->db->executeStatement('UPDATE playlist_like SET created_at = now() - make_interval(mins => :m) WHERE user_id = :u AND playlist_id = :p', [
                        'u' => $this->uid($u), 'p' => $ids[$i], 'm' => $rng->between(30, 4000),
                    ]);
                }
            }
        }
    }

    // ---------------------------------------------------------------- track likes and listens

    /** @param array<int, string> $tracks */
    private function createTrackLikesAndListens(array $tracks, SeedRng $rng): void
    {
        foreach (array_keys(Catalog::users()) as $u) {
            $taste = $this->tasteOf($u, $rng);
            foreach (Catalog::tracks() as $n => $t) {
                $owner = $this->trackOwner($n);
                if ($u === $owner || !$this->canSee($u, $owner, $t[5])) {
                    continue;
                }
                $liked = \in_array($t[2], $taste, true);
                $followed = isset($this->following[$u][$owner]);
                $boost = ($liked ? 0.3 : 0.0) + ($followed ? 0.12 : 0.0);
                $trackId = Uuid::fromString($tracks[$n]);
                if ($rng->float() < 0.03 + $boost * 0.7 + ($t[7] ? 0.04 : 0.0)) {
                    $this->em->wrapInTransaction(fn (): array => $this->likes->like('track', $this->users[$u], $trackId));
                    $this->db->executeStatement('UPDATE track_like SET created_at = now() - make_interval(mins => :m) WHERE user_id = :u AND track_id = :t', ['m' => $rng->between(30, 19000), 'u' => $this->uid($u), 't' => $tracks[$n]]);
                }
                $plays = $rng->float() < 0.08 + $boost ? ($liked ? $rng->between(1, 3) : 1) : 0;
                for ($k = 0; $k < $plays; ++$k) {
                    $ref = $this->durationMs($tracks[$n]);
                    $this->listen($u, $tracks[$n], ListenSource::Player, (int) ($ref * (0.55 + $rng->float() * 0.45)), $ref, null, $rng->between(10, 19000));
                }
            }
        }
    }

    private function durationMs(string $trackId): int
    {
        return (int) $this->db->fetchOne('SELECT duration_ms FROM track WHERE id = :id', ['id' => $trackId]);
    }

    /** Goes through ListenRecorder; the stream-url grant (ADR 010) is issued by the real repository then aged, as if the player had run. */
    private function listen(string $user, string $trackId, ListenSource $source, int $listenedMs, int $referenceMs, ?string $postId, int $minutesAgo): void
    {
        $u = $this->users[$user];
        $t = Uuid::fromString($trackId);
        $this->grants->grant($u->getId(), $t, $referenceMs);
        $this->db->executeStatement('UPDATE listen_grant SET issued_at = now() - make_interval(secs => :s) WHERE user_id = :u AND track_id = :t', ['s' => $referenceMs / 1000 + 5, 'u' => $this->uid($user), 't' => $trackId]);
        [$counted] = $this->listens->record($u, $this->em->getReference(Track::class, $t) ?? throw new \RuntimeException('track'), $source, $listenedMs, $referenceMs, null === $postId ? null : Uuid::fromString($postId));
        if ($counted) {
            $this->db->executeStatement(
                "UPDATE listen SET created_at = now() - make_interval(mins => :m) WHERE user_id = :u AND track_id = :t AND created_at > now() - interval '1 minute'",
                ['m' => $minutesAgo, 'u' => $this->uid($user), 't' => $trackId],
            );
        }
    }

    // ---------------------------------------------------------------- posts

    /**
     * Posts are written here (row + raw visual in quarantine, hashtags); the REAL GenerateClip and
     * ProcessPostVisual handlers then run in a pool of child processes, like the media worker would.
     *
     * @param array<string, User> $users
     * @param array<int, string>  $tracks
     *
     * @return list<array{string, int, string, int}> [post id, track number, author key, hours ago]
     */
    private function createPosts(array $users, array $tracks, ImageSynth $images, callable $log): array
    {
        $pool = $this->visualPool($images);
        $posts = [];
        $queue = [];
        foreach (Catalog::posts() as $i => [$author, $n, $start, $secs, $caption, $visual, $hoursAgo]) {
            $track = $this->em->find(Track::class, $tracks[$n]);
            $user = $this->em->find(User::class, $users[$author]->getId());
            if (null === $track || null === $user) {
                throw new \RuntimeException('post references a missing track or user');
            }
            $post = new Post($user, $track, $start * 1000, $secs * 1000, $caption);
            $pid = $post->getId()->toRfc4122();
            $hasVisual = 'none' !== $visual;
            if ($hasVisual) {
                $ext = 'video' === $visual ? '.mp4' : '.png';
                if ($i < 16) {
                    // Hand-written scenario: its own visual, labelled with the track title.
                    $raw = $this->workDir.'/visual-'.$i.$ext;
                    'video' === $visual ? $images->postVideo(200 + $i, $raw) : $images->postImage(mb_substr(Catalog::tracks()[$n][1], 0, 24), 200 + $i, $raw);
                } else {
                    $raw = $pool[$visual][$i % \count($pool[$visual])];
                }
                $key = 'quarantine/posts/'.$pid.'/visual';
                $this->putFile($key, $raw, 'video' === $visual ? 'video/mp4' : 'image/png');
                $post->attachRawVisual('video' === $visual ? VisualType::Video : VisualType::Image, $key);
                if ($i < 16) {
                    @unlink($raw);
                }
            }
            $this->em->persist($post);
            $this->em->flush();
            $this->attachHashtags($pid, HashtagExtractor::extract($caption));
            $this->db->executeStatement('UPDATE post SET created_at = now() - make_interval(hours => :h) WHERE id = :id', ['h' => $hoursAgo, 'id' => $pid]);
            $queue[$pid] = $hasVisual;
            $posts[] = [$pid, $n, $author, $hoursAgo];
            if (0 === $i % 50) {
                $this->em->clear();
            }
        }
        $this->em->clear();
        $log(\sprintf('  %d posts written, processing clips and visuals', \count($posts)));
        $this->runPool(array_map(
            fn (string $pid, bool $v): array => [$pid, [\PHP_BINARY, 'bin/console', 'app:seed:process-post', $pid, $v ? '1' : '0', '--env=dev', '--no-debug', '--no-interaction']],
            array_keys($queue),
            array_values($queue),
        ));
        // The real handlers keep their ffmpeg timeouts (60/120 s): on a loaded machine a few jobs of the
        // parallel pool can time out. Retry those once, one at a time, before the strict check.
        $failed = $this->db->fetchFirstColumn("SELECT id FROM post WHERE status = 'failed' AND failure_reason = 'processing_error'");
        if ([] !== $failed) {
            $log(\sprintf('  %d posts timed out under load, retried one at a time', \count($failed)));
            foreach ($failed as $pid) {
                $pid = (string) $pid;
                $this->db->executeStatement("UPDATE post SET status = 'processing', failure_reason = NULL WHERE id = :id", ['id' => $pid]);
                $p = new Process([\PHP_BINARY, 'bin/console', 'app:seed:process-post', $pid, ($queue[$pid] ?? false) ? '1' : '0', '--env=dev', '--no-debug', '--no-interaction'], \dirname(__DIR__, 2), null, null, 600.0);
                $p->mustRun();
            }
        }
        $notReady = $this->db->fetchOne("SELECT count(*) FROM post WHERE status <> 'ready'");
        if (0 !== (int) $notReady) {
            throw new \RuntimeException($notReady.' posts did not reach ready');
        }

        return $posts;
    }

    /**
     * A few images and videos synthesized once (in parallel) and re-uploaded for each generated post.
     *
     * @return array{image: list<string>, video: list<string>}
     */
    private function visualPool(ImageSynth $images): array
    {
        $pool = ['image' => [], 'video' => []];
        $procs = [];
        for ($k = 0; $k < 8; ++$k) {
            $pool['image'][] = $this->workDir.'/pool-i'.$k.'.png';
            $procs[] = $images->startPostImage('', 300 + $k, end($pool['image']));
        }
        for ($k = 0; $k < 4; ++$k) {
            $pool['video'][] = $this->workDir.'/pool-v'.$k.'.mp4';
            $procs[] = $images->startPostVideo(400 + $k, end($pool['video']));
        }
        $this->wait($procs);

        return $pool;
    }

    /** @param list<array{string, list<string>}> $jobs [label, argv] */
    private function runPool(array $jobs): void
    {
        $queue = $jobs;
        /** @var array<int, array{Process, string}> $running */
        $running = [];
        while ([] !== $queue || [] !== $running) {
            while ([] !== $queue && \count($running) < self::POST_POOL) {
                [$label, $argv] = array_shift($queue);
                $p = new Process($argv, \dirname(__DIR__, 2), null, null, 600.0);
                $p->start();
                $running[] = [$p, $label];
            }
            foreach ($running as $k => [$p, $label]) {
                if (!$p->isRunning()) {
                    if (!$p->isSuccessful()) {
                        throw new \RuntimeException(\sprintf('job %s failed: %s %s', $label, $p->getErrorOutput(), $p->getOutput()));
                    }
                    unset($running[$k]);
                }
            }
            usleep(50_000);
        }
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

    // ---------------------------------------------------------------- post interactions

    /**
     * @param array<int, string>                    $tracks
     * @param list<array{string, int, string, int}> $posts
     */
    private function createPostInteractions(array $tracks, array $posts, SeedRng $rng): void
    {
        $keys = array_keys(Catalog::users());
        foreach ($posts as [$pid, $n, $author, $hours]) {
            $vis = Catalog::tracks()[$n][5];
            $owner = $this->trackOwner($n);
            $genre = Catalog::tracks()[$n][2];
            $postId = Uuid::fromString($pid);
            $window = max(2, $hours * 60 - 1);
            $clipMs = (int) $this->db->fetchOne('SELECT clip_duration_ms FROM post WHERE id = :p', ['p' => $pid]);
            $comments = 0;
            foreach ($keys as $u) {
                if ($u === $author || !$this->canSee($u, $owner, $vis)) {
                    continue;
                }
                $user = $this->users[$u];
                $uid = $this->uid($u);
                $follows = isset($this->following[$u][$author]);
                $likesGenre = \in_array($genre, $this->tasteOf($u, $rng), true);
                $f = ($follows ? 1.0 : 0.0) + ($likesGenre ? 0.6 : 0.0);

                if ($rng->float() < 0.04 + 0.22 * $f) {
                    $this->em->wrapInTransaction(fn (): array => $this->likes->like('post', $user, $postId));
                    $this->db->executeStatement('UPDATE post_like SET created_at = now() - make_interval(mins => :m) WHERE user_id = :u AND post_id = :p', ['m' => $rng->between(1, $window), 'u' => $uid, 'p' => $pid]);
                }
                if ($comments < 5 && $rng->float() < 0.02 + 0.08 * $f) {
                    ++$comments;
                    $this->comment($user, $pid, Catalog::comments()[$rng->between(0, \count(Catalog::comments()) - 1)], $rng->between(1, $window));
                }
                if ($rng->float() < 0.12 + 0.4 * $f) {
                    $watched = (int) ($clipMs * (0.3 + $rng->float() * 0.7));
                    $this->em->wrapInTransaction(fn (): int => $this->views->record($user, [$pid => $watched]));
                    $m = $rng->between(1, $window);
                    $this->db->executeStatement('UPDATE post_view SET first_seen_at = now() - make_interval(mins => :m), last_seen_at = now() - make_interval(mins => :m) WHERE user_id = :u AND post_id = :p', ['m' => $m, 'u' => $uid, 'p' => $pid]);
                    if ($rng->float() < 0.3) {
                        $this->listen($u, $tracks[$n], ListenSource::Feed, $clipMs, $clipMs, $pid, $m);
                    }
                }
                // ADR 015: only public audiences, never one's own post.
                if ('public' === $vis && $rng->float() < 0.012 + 0.035 * $f) {
                    $this->em->wrapInTransaction(fn (): array => $this->reposts->repost($user, $postId));
                    $this->db->executeStatement('UPDATE repost SET created_at = now() - make_interval(mins => :m) WHERE user_id = :u AND post_id = :p', ['m' => $rng->between(1, $window), 'u' => $uid, 'p' => $pid]);
                }
            }
            $this->em->clear();
        }
    }

    private function comment(User $user, string $postId, string $body, int $minutesAgo): void
    {
        $post = $this->em->getReference(Post::class, Uuid::fromString($postId)) ?? throw new \RuntimeException('post');
        $comment = new Comment($post, $user, $body);
        $this->em->wrapInTransaction(function () use ($comment, $post, $user): void {
            $this->comments->create($comment);
            $this->notifications->recordOn(NotificationType::Comment, $user, $post->getId());
        });
        $this->db->executeStatement('UPDATE comment SET created_at = now() - make_interval(mins => :m) WHERE id = :id', ['m' => $minutesAgo, 'id' => $comment->getId()->toRfc4122()]);
    }

    // ---------------------------------------------------------------- finishing

    /** @param array<int, string> $tracks */
    private function stagger(array $tracks): void
    {
        $i = 0;
        foreach (array_keys(Catalog::users()) as $key) {
            $this->db->executeStatement('UPDATE app_user SET created_at = now() - make_interval(hours => :h) WHERE id = :id', ['h' => (int) (24 * 60 - $i * 36), 'id' => $this->uid($key)]);
            $this->db->executeStatement('UPDATE artist SET created_at = now() - make_interval(hours => :h) WHERE owner_id = :id', ['h' => (int) (24 * 58 - $i * 36), 'id' => $this->uid($key)]);
            ++$i;
        }
        // Tracks older than the oldest post (14 days), oldest first.
        $last = [] === $tracks ? 0 : max(array_keys($tracks));
        foreach ($tracks as $n => $id) {
            $this->db->executeStatement('UPDATE track SET created_at = now() - make_interval(hours => :h), updated_at = now() - make_interval(hours => :h) WHERE id = :id', ['h' => 340 + ($last - $n) * 3, 'id' => $id]);
        }
    }

    /** Only the tables the seed fills with plain SQL (playlists, hashtags); everything else is maintained by the repositories. */
    private function recountDerived(): void
    {
        foreach ([
            'UPDATE playlist p SET item_count = (SELECT count(*) FROM playlist_item WHERE playlist_id = p.id), like_count = (SELECT count(*) FROM playlist_like WHERE playlist_id = p.id)',
            'UPDATE hashtag h SET post_count = (SELECT count(*) FROM post_hashtag WHERE hashtag_id = h.id)',
        ] as $sql) {
            $this->db->executeStatement($sql);
        }
    }

    /** The domain counters must equal the rows: a drift means a service was bypassed. */
    private function checkCounters(): void
    {
        $bad = $this->db->fetchAllAssociative(
            "SELECT 'user' AS k, count(*) AS n FROM app_user u WHERE followers_count <> (SELECT count(*) FROM follow WHERE followed_id = u.id) OR following_count <> (SELECT count(*) FROM follow WHERE follower_id = u.id)"
            ." UNION ALL SELECT 'track', count(*) FROM track t WHERE like_count <> (SELECT count(*) FROM track_like WHERE track_id = t.id) OR play_count <> (SELECT count(*) FROM listen WHERE track_id = t.id)"
            ." UNION ALL SELECT 'post', count(*) FROM post p WHERE like_count <> (SELECT count(*) FROM post_like WHERE post_id = p.id) OR comment_count <> (SELECT count(*) FROM comment WHERE post_id = p.id) OR view_count <> (SELECT count(*) FROM post_view WHERE post_id = p.id) OR repost_count <> (SELECT count(*) FROM repost WHERE post_id = p.id)",
        );
        foreach ($bad as $row) {
            if (0 !== (int) $row['n']) {
                throw new \RuntimeException(\sprintf('counter drift on %s: %d rows', $row['k'], $row['n']));
            }
        }
    }

    private function recompute(): void
    {
        foreach ($this->users as $user) {
            $this->affinity->recompute($user->getId());
        }
        $this->trending->recompute();
    }
}
