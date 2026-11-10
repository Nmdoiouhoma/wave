<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Entity\Post;
use App\Enum\FailureReason;
use App\Enum\MediaStatus;
use App\Enum\VisualType;
use App\Media\ClipProcessor;
use App\Media\EventListener\MarkMediaFailedOnFinalFailure;
use App\Media\FfmpegRunner;
use App\Media\ImageProcessor;
use App\Media\MessageHandler\GenerateClipHandler;
use App\Media\MessageHandler\ProcessPostVisualHandler;
use App\Media\PostMediaFailure;
use App\Media\PostVisualProcessor;
use App\Message\GenerateClip;
use App\Message\ProcessPostVisual;
use App\Storage\StorageArea;
use App\Tests\Media\Support\HandlerTestKit;
use App\Tests\Media\Support\MediaFixtures;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Process\Process;

/**
 * W-31: GenerateClip and ProcessPostVisual end to end (real ffmpeg, in-memory storage, no database).
 * The row lock is the Doctrine layer's job (LockedFind, as for tracks); what is asserted here is the
 * state machine: both completion orders, failure of either, redelivery, post deleted meanwhile.
 */
final class PostMediaHandlersTest extends TestCase
{
    private MediaFixtures $fx;
    private HandlerTestKit $kit;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $this->fx = new MediaFixtures();
        $this->kit = new HandlerTestKit(self::createStub(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        foreach ($this->temp as $f) {
            @unlink($f);
        }
    }

    // ------------------------------------------------------------ GenerateClip

    public function testClipHasExactDurationFadesAndNoVisualMeansReady(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0, 0.5), 3_000, 15_000);

        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));

        $key = 'audio/clips/'.$post->getId()->toRfc4122().'.m4a';
        self::assertSame($key, $post->getClipKey());
        self::assertSame(MediaStatus::Ready, $post->getStatus(), 'no visual: the clip alone makes it ready');
        $file = $this->fetch(StorageArea::Private, $key, '.m4a');
        $probe = $this->fx->probe($file);
        self::assertEqualsWithDelta(15.0, (float) $probe['format']['duration'], ClipProcessor::DURATION_TOLERANCE_MS / 1000);
        self::assertSame('aac', $probe['streams'][0]['codec_name']);
        self::assertSame(44100, (int) $probe['streams'][0]['sample_rate']);
        // 300 ms fades: the first and last 40 ms are far quieter than the middle.
        $middle = $this->maxVolume($file, 7.0);
        self::assertLessThan($middle - 12.0, $this->maxVolume($file, 0.0));
        self::assertLessThan($middle - 12.0, $this->maxVolume($file, 14.96));
    }

    public function testRedeliveryOfClipIsHarmless(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000);
        $message = new GenerateClip($post->getId()->toRfc4122());
        $handler = $this->clipHandler();

        $handler($message);
        $before = $this->kit->private->lastModified((string) $post->getClipKey());
        $handler($message);

        self::assertSame(MediaStatus::Ready, $post->getStatus());
        self::assertSame($before, $this->kit->private->lastModified((string) $post->getClipKey()));
    }

    public function testClipOfGoneOrMalformedPostIsDropped(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000);
        $this->kit->remove($post, $post->getId());
        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));
        ($this->clipHandler())(new GenerateClip('not-a-uuid'));

        self::assertFalse($this->kit->has(StorageArea::Private, 'audio/clips/'.$post->getId()->toRfc4122().'.m4a'));
    }

    /** Revue qa vague 1, bas 3: a key written for a post deleted meanwhile is purged. */
    public function testClipKeyIsPurgedWhenThePostIsDeletedDuringProcessing(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000);
        $this->kit->vanishAfter[Post::class] = 1; // found at the start, gone under the lock

        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));

        self::assertNull($post->getClipKey());
        self::assertFalse($this->kit->has(StorageArea::Private, 'audio/clips/'.$post->getId()->toRfc4122().'.m4a'));
    }

    public function testClipOutOfSourceRangeFailsThePost(): void
    {
        // The track claims 20 s but only holds 8 s: the 15 s clip cannot be produced.
        $post = $this->kit->post($this->fx->m4a(8.0), 0, 15_000);

        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertSame(FailureReason::ProcessingError, $post->getFailureReason());
        self::assertFalse($this->kit->has(StorageArea::Private, 'audio/clips/'.$post->getId()->toRfc4122().'.m4a'));
    }

    // ------------------------------------------------------- ready, both orders

    public function testReadyWhenClipThenVisual(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->jpeg(), VisualType::Image);

        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));
        self::assertSame(MediaStatus::Processing, $post->getStatus(), 'visual still missing');
        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Ready, $post->getStatus());
    }

    public function testReadyWhenVisualThenClip(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->jpeg(), VisualType::Image);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));
        self::assertSame(MediaStatus::Processing, $post->getStatus(), 'clip still missing');
        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Ready, $post->getStatus());
    }

    public function testVisualFailureFailsThePostAndTheClipHandlerThenSkips(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->bytes('.bin', 'not an image'), VisualType::Image);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));
        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertSame(FailureReason::InvalidMedia, $post->getFailureReason());
        self::assertNull($post->getClipKey());
        self::assertFalse($this->kit->has(StorageArea::Private, 'audio/clips/'.$post->getId()->toRfc4122().'.m4a'), 'clip handler skipped');
    }

    public function testClipFailureFailsThePostAndTheVisualHandlerThenSkips(): void
    {
        $post = $this->kit->post($this->fx->m4a(8.0), 0, 15_000, $this->fx->jpeg(), VisualType::Image);

        ($this->clipHandler())(new GenerateClip($post->getId()->toRfc4122()));
        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertNull($post->getVisualKey());
        self::assertSame([], $this->listPublic());
    }

    public function testFinalFailureOfEitherHandlerMarksThePostFailed(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000);
        $listener = new MarkMediaFailedOnFinalFailure($this->kit->em, new NullLogger());

        $retry = new WorkerMessageFailedEvent(new Envelope(new GenerateClip($post->getId()->toRfc4122())), 'media', new \RuntimeException('boom'));
        $retry->setForRetry();
        $listener($retry);
        self::assertSame(MediaStatus::Processing, $post->getStatus());

        $listener(new WorkerMessageFailedEvent(new Envelope(new ProcessPostVisual($post->getId()->toRfc4122())), 'media', new \RuntimeException('boom')));
        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertSame(FailureReason::ProcessingError, $post->getFailureReason());
    }

    // ------------------------------------------------------------ ProcessPostVisual

    public function testImageBecomesWebpWithin1080x1920AndQuarantineIsDeleted(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->jpeg(3000, 4000), VisualType::Image);
        $id = $post->getId()->toRfc4122();
        $message = new ProcessPostVisual($id);
        $handler = $this->visualHandler();

        $handler($message);

        $key = (string) $post->getVisualKey();
        self::assertMatchesRegularExpression('#^images/posts/'.$id.'/[0-9a-f-]{36}\.webp$#', $key);
        self::assertNull($post->getVisualPosterKey());
        self::assertNull($post->getVisualRawKey());
        self::assertFalse($this->kit->has(StorageArea::Private, 'quarantine/posts/'.$id.'/visual'));
        $video = $this->fx->probe($this->fetch(StorageArea::Public, $key, '.webp'))['streams'][0];
        self::assertSame('webp', $video['codec_name']);
        self::assertLessThanOrEqual(1080, $video['width']);
        self::assertLessThanOrEqual(1920, $video['height']);
        self::assertSame(1080, $video['width'], 'fitted, not cropped: 3000x4000 -> 1080x1440');
        self::assertSame(1440, $video['height']);

        $handler($message); // redelivery: skipped
        self::assertSame($key, $post->getVisualKey());
    }

    public function testVideoIsMutedH264Within720x1280At30FpsWithWebpPoster(): void
    {
        // 1920x1080 at 60 fps with an audio track: everything must be brought down.
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->mp4(3.0, size: '1920x1080', fps: 60), VisualType::Video);
        $id = $post->getId()->toRfc4122();

        ($this->visualHandler())(new ProcessPostVisual($id));

        $videoKey = (string) $post->getVisualKey();
        $posterKey = (string) $post->getVisualPosterKey();
        self::assertMatchesRegularExpression('#^videos/posts/'.$id.'/[0-9a-f-]{36}\.mp4$#', $videoKey);
        self::assertMatchesRegularExpression('#^images/posts/'.$id.'/[0-9a-f-]{36}-poster\.webp$#', $posterKey);
        $probe = $this->fx->probe($this->fetch(StorageArea::Public, $videoKey, '.mp4'));
        self::assertCount(1, $probe['streams'], 'no audio stream');
        $stream = $probe['streams'][0];
        self::assertSame('video', $stream['codec_type']);
        self::assertSame('h264', $stream['codec_name']);
        self::assertSame('Main', $stream['profile']);
        self::assertLessThanOrEqual(720, $stream['width']);
        self::assertLessThanOrEqual(1280, $stream['height']);
        self::assertSame(0, $stream['width'] % 2);
        [$n, $d] = array_map('intval', explode('/', $stream['r_frame_rate']));
        self::assertLessThanOrEqual(30.0, $n / $d);
        self::assertEqualsWithDelta(3.0, (float) $probe['format']['duration'], 0.2);
        $poster = $this->fx->probe($this->fetch(StorageArea::Public, $posterKey, '.webp'))['streams'][0];
        self::assertSame('webp', $poster['codec_name']);
        self::assertLessThanOrEqual(720, $poster['width']);
        self::assertFalse($this->kit->has(StorageArea::Private, 'quarantine/posts/'.$id.'/visual'));
    }

    public function testFourKVideoFitsTheWorkerMemoryLimit(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->mp4(2.0, size: '3840x2160', fps: 30), VisualType::Video);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Processing, $post->getStatus(), (string) $post->getFailureReason()?->value);
        $stream = $this->fx->probe($this->fetch(StorageArea::Public, (string) $post->getVisualKey(), '.mp4'))['streams'][0];
        self::assertSame(720, $stream['width']);
        self::assertSame(404, $stream['height'], '720x405 rounded to even');
    }

    public function testSmallPortraitVideoIsNotUpscaled(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->mp4(2.0, audio: false, size: '320x568', fps: 24), VisualType::Video);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        $stream = $this->fx->probe($this->fetch(StorageArea::Public, (string) $post->getVisualKey(), '.mp4'))['streams'][0];
        self::assertSame(320, $stream['width']);
        self::assertSame(568, $stream['height']);
    }

    public function testSeventySecondVideoIsTooLong(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->mp4(70.0, audio: false, size: '160x120', fps: 5), VisualType::Video);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertSame(FailureReason::TooLong, $post->getFailureReason());
        self::assertSame([], $this->listPublic());
    }

    /**
     * @return iterable<string, array{string, FailureReason}>
     */
    public static function invalidVideos(): iterable
    {
        yield 'audio-only mp4' => ['audioOnly', FailureReason::InvalidMedia];
        yield 'png renamed as video' => ['png', FailureReason::InvalidMedia];
        yield 'text with an mp4 ftyp header' => ['ftypText', FailureReason::InvalidMedia];
        yield 'ffconcat playlist' => ['playlist', FailureReason::InvalidMedia];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVideos')]
    public function testInvalidVideoIsRejected(string $kind, FailureReason $reason): void
    {
        $file = match ($kind) {
            'audioOnly' => $this->fx->mp4(3.0, video: false),
            'png' => $this->fx->png(),
            'ftypText' => $this->fx->bytes('.mp4', "\0\0\0\x18ftypmp42\0\0\0\0mp42isom".str_repeat('x', 200)),
            'playlist' => $this->fx->bytes('.mp4', "ffconcat version 1.0\nfile /etc/passwd\n"),
            default => throw new \LogicException("Unknown invalid video kind: {$kind}"),
        };
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $file, VisualType::Video);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertSame($reason, $post->getFailureReason());
        self::assertNull($post->getVisualKey());
        self::assertSame([], $this->listPublic());
        self::assertTrue($this->kit->has(StorageArea::Private, 'quarantine/posts/'.$post->getId()->toRfc4122().'/visual'), 'quarantine kept for CleanupQuarantine');
    }

    public function testImageDeclaredButVideoUploadedIsRejected(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->mp4(2.0), VisualType::Image);

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame(MediaStatus::Failed, $post->getStatus());
        self::assertSame(FailureReason::InvalidMedia, $post->getFailureReason());
    }

    public function testVisualKeysArePurgedWhenThePostIsDeletedDuringProcessing(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->mp4(2.0), VisualType::Video);
        $this->kit->vanishAfter[Post::class] = 1;

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertNull($post->getVisualKey());
        self::assertSame([], $this->listPublic(), 'video and poster purged');
        self::assertFalse($this->kit->has(StorageArea::Private, 'quarantine/posts/'.$post->getId()->toRfc4122().'/visual'));
    }

    public function testVisualOfDeletedPostIsSkipped(): void
    {
        $post = $this->kit->post($this->fx->m4a(20.0), 0, 15_000, $this->fx->jpeg(), VisualType::Image);
        $this->kit->remove($post, $post->getId());

        ($this->visualHandler())(new ProcessPostVisual($post->getId()->toRfc4122()));

        self::assertSame([], $this->listPublic());
    }

    // ------------------------------------------------------------ helpers

    private function clipHandler(): GenerateClipHandler
    {
        return new GenerateClipHandler($this->kit->em, $this->kit->storage, new ClipProcessor($this->runner()), new PostMediaFailure($this->kit->em), new NullLogger());
    }

    private function visualHandler(): ProcessPostVisualHandler
    {
        $runner = $this->runner();

        return new ProcessPostVisualHandler($this->kit->em, $this->kit->storage, new PostVisualProcessor($runner, new ImageProcessor($runner)), new PostMediaFailure($this->kit->em), new NullLogger(), 'test-secret');
    }

    private function runner(): FfmpegRunner
    {
        return new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin());
    }

    private function fetch(StorageArea $area, string $key, string $suffix): string
    {
        $fs = StorageArea::Public === $area ? $this->kit->public : $this->kit->private;
        $path = $this->fx->path($suffix);
        file_put_contents($path, $fs->read($key));

        return $path;
    }

    /** @return list<string> */
    private function listPublic(): array
    {
        return array_values(array_map(static fn ($f): string => $f->path(), $this->kit->public->listContents('', true)->toArray()));
    }

    /** Max volume (dB) of the 40 ms starting at $start seconds. */
    private function maxVolume(string $file, float $start): float
    {
        $p = new Process([MediaFixtures::ffmpegBin(), '-hide_banner', '-nostats', '-ss', (string) $start, '-t', '0.04', '-i', $file, '-af', 'volumedetect', '-f', 'null', '-']);
        $p->mustRun();
        self::assertSame(1, preg_match('/max_volume: (-?[\d.]+) dB/', $p->getErrorOutput(), $m));

        return (float) $m[1];
    }
}
