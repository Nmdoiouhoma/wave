<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Media\EventListener\MarkMediaFailedOnFinalFailure;
use App\Media\FfmpegRunner;
use App\Media\ImageProcessor;
use App\Media\MessageHandler\CleanupQuarantineHandler;
use App\Media\MessageHandler\ProcessImageHandler;
use App\Media\MessageHandler\ProcessTrackHandler;
use App\Media\MessageHandler\PurgeObjectsHandler;
use App\Media\QuarantineScanner;
use App\Media\TrackAudioProcessor;
use App\Message\CleanupQuarantine;
use App\Message\ProcessImage;
use App\Message\ProcessTrack;
use App\Message\PurgeObjects;
use App\Storage\StorageArea;
use App\Tests\Media\Support\HandlerTestKit;
use App\Tests\Media\Support\MediaFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\SendFailedMessageForRetryListener;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Retry\MultiplierRetryStrategy;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Uid\Uuid;

/**
 * W-10: handlers end to end (real ffmpeg, in-memory storage, no database),
 * focused on idempotency on redelivery.
 */
final class MediaHandlersTest extends TestCase
{
    private MediaFixtures $fx;
    private HandlerTestKit $kit;

    protected function setUp(): void
    {
        $this->fx = new MediaFixtures();
        $this->kit = new HandlerTestKit(self::createStub(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->fx->cleanup();
    }

    // ---------------------------------------------------------- ProcessTrack

    public function testProcessTrackMakesTrackReadyAndIsIdempotent(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $track = $this->kit->track($this->fx->mp3(12.0));
        $rawKey = (string) $track->getRawKey();
        $message = new ProcessTrack($track->getId()->toRfc4122());
        $handler = $this->trackHandler();

        $handler($message);

        $audioKey = 'audio/tracks/'.$track->getId()->toRfc4122().'.m4a';
        self::assertSame(MediaStatus::Ready, $track->getStatus());
        self::assertSame($audioKey, $track->getAudioKey());
        self::assertEqualsWithDelta(12_000, (int) $track->getDurationMs(), 150);
        self::assertNull($track->getRawKey());
        self::assertTrue($this->kit->has(StorageArea::Private, $audioKey));
        self::assertFalse($this->kit->has(StorageArea::Private, $rawKey), 'quarantine deleted on success');

        // Redelivery: track already ready => no-op (nothing rewritten, no failure).
        $before = $this->kit->private->lastModified($audioKey);
        $handler($message);
        self::assertSame(MediaStatus::Ready, $track->getStatus());
        self::assertSame($before, $this->kit->private->lastModified($audioKey));
    }

    public function testProcessTrackAcceptsMp4WithVideo(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $track = $this->kit->track($this->fx->mp4(8.0));

        ($this->trackHandler())(new ProcessTrack($track->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Ready, $track->getStatus());
        self::assertEqualsWithDelta(8_000, (int) $track->getDurationMs(), 150);
    }

    /**
     * FB-10: a silent video is a business rejection (`no_audio`): the track is
     * failed, the message is acknowledged like any success, nothing is retried
     * and nothing reaches the `failed` transport.
     */
    public function testVideoOnlyMp4IsNoAudioAndAcknowledgedThroughARealWorker(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $track = $this->kit->track($this->fx->mp4(3.0, audio: false));

        [$media, $failed] = $this->consumeOnce(new ProcessTrack($track->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $track->getStatus());
        self::assertSame(FailureReason::NoAudio, $track->getFailureReason());
        self::assertCount(1, $media->getAcknowledged(), 'acknowledged');
        self::assertCount(0, $media->getRejected(), 'not rejected');
        self::assertCount(1, $media->getSent(), 'only the original send, no retry re-send');
        self::assertCount(0, $failed->getSent(), 'nothing in the failed transport');
    }

    public function testProcessTrackMarksRenamedPngFailed(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $png = $this->fx->png();
        $track = $this->kit->track($png);

        ($this->trackHandler())(new ProcessTrack($track->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $track->getStatus());
        self::assertSame(FailureReason::InvalidMedia, $track->getFailureReason());
        self::assertNotNull($track->getRawKey(), 'quarantine kept 7 days after failure');
        self::assertTrue($this->kit->has(StorageArea::Private, (string) $track->getRawKey()));
    }

    public function testProcessTrackIgnoresDeletedTrackAndBadIds(): void
    {
        [$media, $failed] = $this->consumeOnce(new ProcessTrack(Uuid::v7()->toRfc4122()));
        self::assertCount(1, $media->getAcknowledged());

        [$media, $failed] = $this->consumeOnce(new ProcessTrack('not-a-uuid'));
        self::assertCount(1, $media->getAcknowledged());
        self::assertCount(0, $failed->getSent());
    }

    /**
     * Runs one message through a real Messenger Worker wired like prod (retry
     * strategy 2 retries, failure transport), on in-memory transports.
     *
     * @return array{InMemoryTransport, InMemoryTransport}
     */
    private function consumeOnce(object $message): array
    {
        $media = new InMemoryTransport();
        $failed = new InMemoryTransport();
        $media->send(new Envelope($message));

        $bus = new MessageBus([new HandleMessageMiddleware(new HandlersLocator([ProcessTrack::class => [$this->trackHandler()]]))]);
        $dispatcher = new EventDispatcher();
        $map = static fn (array $services): ContainerInterface => new class($services) implements ContainerInterface {
            /** @param array<string, object> $s */
            public function __construct(private readonly array $s)
            {
            }

            public function get(string $id): object
            {
                return $this->s[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->s[$id]);
            }
        };
        // Retries are re-sent to the receiver's own transport; final failures go
        // to the failure transport configured for that receiver (as in prod).
        $dispatcher->addSubscriber(new SendFailedMessageForRetryListener($map(['media' => $media]), $map(['media' => new MultiplierRetryStrategy(2, 0)])));
        $dispatcher->addSubscriber(new SendFailedMessageToFailureTransportListener($map(['media' => $failed])));
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));

        new Worker(['media' => $media], $bus, $dispatcher)->run(['sleep' => 0]);

        return [$media, $failed];
    }

    public function testFinalFailureMarksProcessingTrackAsProcessingError(): void
    {
        $file = $this->fx->bytes('.mp3', 'x');
        $track = $this->kit->track($file);
        $message = new ProcessTrack($track->getId()->toRfc4122());
        $listener = new MarkMediaFailedOnFinalFailure($this->kit->em, new NullLogger());

        $retry = new WorkerMessageFailedEvent(new Envelope($message), 'media', new \RuntimeException('boom'));
        $retry->setForRetry();
        $listener($retry);
        self::assertSame(MediaStatus::Processing, $track->getStatus(), 'retry scheduled: untouched');

        // Retries exhausted (willRetry() is false unless setForRetry() was called).
        $listener(new WorkerMessageFailedEvent(new Envelope($message), 'media', new \RuntimeException('boom')));
        self::assertSame(MediaStatus::Failed, $track->getStatus());
        self::assertSame(FailureReason::ProcessingError, $track->getFailureReason());
    }

    // ---------------------------------------------------------- ProcessImage

    public function testProcessImageReplacesAvatarPurgesOldKeyAndIsIdempotent(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $user = $this->kit->user();
        $uid = $user->getId()->toRfc4122();
        $old = \sprintf('images/avatars/users/%s/%s.webp', $uid, Uuid::v7()->toRfc4122());
        $user->setAvatarKey($old);
        $version = Uuid::v7()->toRfc4122();
        $quarantine = \sprintf('quarantine/avatars/users/%s/%s', $uid, $version);
        $this->kit->putPrivate($quarantine, $this->fx->jpeg());
        $message = new ProcessImage(ProcessImage::TARGET_USER_AVATAR, $uid, $quarantine);
        $handler = $this->imageHandler();

        $handler($message);

        $new = \sprintf('images/avatars/users/%s/%s.webp', $uid, $version);
        self::assertSame($new, $user->getAvatarKey());
        self::assertTrue($this->kit->has(StorageArea::Public, $new));
        self::assertFalse($this->kit->has(StorageArea::Private, $quarantine));
        self::assertEquals([new PurgeObjects('public', [$old])], $this->kit->dispatched);

        // Redelivery: no new purge, key unchanged.
        $handler($message);
        self::assertSame($new, $user->getAvatarKey());
        self::assertCount(1, $this->kit->dispatched);
    }

    public function testOlderImageNeverReplacesNewerOne(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $artist = $this->kit->artist();
        $aid = $artist->getId()->toRfc4122();
        $olderVersion = Uuid::v7()->toRfc4122();
        usleep(2000);
        $current = \sprintf('images/avatars/artists/%s/%s.webp', $aid, Uuid::v7()->toRfc4122());
        $artist->setAvatarKey($current);
        $quarantine = \sprintf('quarantine/avatars/artists/%s/%s', $aid, $olderVersion);
        $this->kit->putPrivate($quarantine, $this->fx->png());

        ($this->imageHandler())(new ProcessImage(ProcessImage::TARGET_ARTIST_AVATAR, $aid, $quarantine));

        self::assertSame($current, $artist->getAvatarKey());
        self::assertSame([], $this->kit->dispatched);
    }

    public function testInvalidImageIsDroppedAndPreviousCoverKept(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $track = $this->kit->track($this->fx->mp3(2.0));
        $tid = $track->getId()->toRfc4122();
        $previous = \sprintf('images/covers/%s/%s.webp', $tid, Uuid::v7()->toRfc4122());
        $track->setCoverKey($previous);
        $quarantine = \sprintf('quarantine/covers/%s/%s', $tid, Uuid::v7()->toRfc4122());
        $this->kit->putPrivate($quarantine, $this->fx->bytes('.png', 'not an image at all'));

        ($this->imageHandler())(new ProcessImage(ProcessImage::TARGET_TRACK_COVER, $tid, $quarantine));

        self::assertSame($previous, $track->getCoverKey());
        self::assertSame([], $this->kit->dispatched);
    }

    public function testImageForDeletedTargetOrForeignKeyIsDropped(): void
    {
        $uid = Uuid::v7()->toRfc4122();
        $quarantine = \sprintf('quarantine/avatars/users/%s/%s', $uid, Uuid::v7()->toRfc4122());
        $this->kit->putPrivate($quarantine, $this->fx->bytes('.png', 'x'));
        $handler = $this->imageHandler();

        $handler(new ProcessImage(ProcessImage::TARGET_USER_AVATAR, $uid, $quarantine));
        self::assertFalse($this->kit->has(StorageArea::Private, $quarantine), 'target gone: quarantine removed');

        // A key outside the target's quarantine directory is never touched.
        $this->kit->putPrivate('audio/tracks/x.m4a', $this->fx->bytes('.m4a', 'x'));
        $handler(new ProcessImage(ProcessImage::TARGET_USER_AVATAR, $uid, 'audio/tracks/x.m4a'));
        self::assertTrue($this->kit->has(StorageArea::Private, 'audio/tracks/x.m4a'));
    }

    // ---------------------------------------------------------- PurgeObjects

    public function testPurgeObjectsIsIdempotent(): void
    {
        $this->kit->public->write('images/a.webp', 'a');
        $this->kit->public->write('images/b.webp', 'b');
        $handler = new PurgeObjectsHandler($this->kit->storage, new NullLogger());
        $message = new PurgeObjects('public', ['images/a.webp', 'images/b.webp', 'images/missing.webp', '../escape']);

        $handler($message);
        $handler($message);

        self::assertFalse($this->kit->has(StorageArea::Public, 'images/a.webp'));
        self::assertFalse($this->kit->has(StorageArea::Public, 'images/b.webp'));
    }

    // ---------------------------------------------------------- CleanupQuarantine

    public function testCleanupQuarantineSweepsFailedAndOrphansAndIsIdempotent(): void
    {
        $failedKey = 'quarantine/tracks/0192f3a4-0000-7000-8000-000000000001/source';
        $orphanKey = 'quarantine/avatars/users/0192f3a4-0000-7000-8000-000000000002/0192f3a4-0000-7000-8000-000000000003';
        $referencedKey = 'quarantine/tracks/0192f3a4-0000-7000-8000-000000000004/source';
        foreach ([$failedKey, $orphanKey, $referencedKey] as $key) {
            $this->kit->private->write($key, 'x');
        }

        // DB double: one failed track row on the first run, nothing afterwards;
        // $referencedKey is still referenced by a processing track; no post table yet.
        $failedRows = [[['id' => '0192f3a4-0000-7000-8000-000000000001', 'k' => $failedKey]]];
        $db = self::createStub(Connection::class);
        $db->method('fetchOne')->willReturnCallback(static fn (string $sql, array $params): ?string => 'public.track' === $params['t'] ? 'track' : null);
        $db->method('fetchAllAssociative')->willReturnCallback(static function () use (&$failedRows): array {
            return array_shift($failedRows) ?? [];
        });
        $db->method('fetchFirstColumn')->willReturn([$referencedKey]);
        $nulled = [];
        $db->method('executeStatement')->willReturnCallback(static function (string $sql, array $params) use (&$nulled): int {
            $nulled[] = $params['k'];

            return 1;
        });

        $scanner = new class([$orphanKey, $referencedKey]) implements QuarantineScanner {
            /** @param list<string> $keys */
            public function __construct(private readonly array $keys)
            {
            }

            public function keysModifiedBefore(\DateTimeImmutable $before): iterable
            {
                return $this->keys;
            }
        };
        $handler = new CleanupQuarantineHandler($db, $this->kit->storage, new MockClock(), new NullLogger(), $scanner);

        $handler(new CleanupQuarantine());
        $handler(new CleanupQuarantine());

        self::assertFalse($this->kit->has(StorageArea::Private, $failedKey));
        self::assertFalse($this->kit->has(StorageArea::Private, $orphanKey));
        self::assertTrue($this->kit->has(StorageArea::Private, $referencedKey));
        self::assertSame([$failedKey], $nulled);
    }

    private function trackHandler(): ProcessTrackHandler
    {
        return new ProcessTrackHandler(
            $this->kit->em,
            $this->kit->storage,
            new TrackAudioProcessor(new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin())),
            new NullLogger(),
        );
    }

    private function imageHandler(): ProcessImageHandler
    {
        return new ProcessImageHandler(
            $this->kit->em,
            $this->kit->storage,
            new ImageProcessor(new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin())),
            $this->kit->bus,
            new NullLogger(),
        );
    }
}
