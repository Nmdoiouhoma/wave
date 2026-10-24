<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\FfmpegRunner;
use App\Media\ImageProcessor;
use App\Media\ImageVariant;
use App\Tests\Media\Support\MediaFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W-10 ProcessImage AC: WebP at the sizes of DATA_MODEL §14, non-images rejected.
 */
final class ImageProcessorTest extends TestCase
{
    private MediaFixtures $fx;
    private ImageProcessor $processor;

    protected function setUp(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $this->fx = new MediaFixtures();
        $this->processor = new ImageProcessor(new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin()));
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    /**
     * @return iterable<string, array{string, ImageVariant, int, int}>
     */
    public static function conversions(): iterable
    {
        yield 'jpeg landscape -> cover 800' => ['jpeg', ImageVariant::Cover, 800, 800];
        yield 'png -> avatar 400' => ['png', ImageVariant::Avatar, 400, 400];
        yield 'webp small -> avatar upscaled crop 400' => ['webp', ImageVariant::Avatar, 400, 400];
        yield 'jpeg 1200x900 -> post visual fit in 1080x1920' => ['jpeg', ImageVariant::PostVisual, 1080, 810];
    }

    #[DataProvider('conversions')]
    public function testConvertsToWebpAtVariantSize(string $kind, ImageVariant $variant, int $width, int $height): void
    {
        $source = match ($kind) {
            'jpeg' => $this->fx->jpeg(1200, 900),
            'png' => $this->fx->png(640, 480),
            default => $this->fx->webp(300, 200),
        };
        $target = $this->fx->path('.webp');

        $this->processor->toWebp($source, $target, $variant);

        self::assertSame('RIFF', substr((string) file_get_contents($target), 0, 4));
        $probe = $this->fx->probe($target);
        self::assertIsArray($probe['streams']);
        $stream = $probe['streams'][0];
        self::assertIsArray($stream);
        self::assertSame('webp', $stream['codec_name']);
        self::assertSame($width, $stream['width']);
        self::assertSame($height, $stream['height']);
    }

    public function testPostVisualIsNeverUpscaled(): void
    {
        $target = $this->fx->path('.webp');
        $this->processor->toWebp($this->fx->png(300, 500), $target, ImageVariant::PostVisual);

        $stream = $this->fx->probe($target)['streams'][0] ?? null;
        self::assertIsArray($stream);
        self::assertSame([300, 500], [$stream['width'], $stream['height']]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonImages(): iterable
    {
        yield 'text' => ['text'];
        yield 'mp3' => ['mp3'];
        yield 'exe' => ['exe'];
    }

    #[DataProvider('nonImages')]
    public function testNonImageIsRejected(string $kind): void
    {
        $source = match ($kind) {
            'text' => $this->fx->bytes('.png', str_repeat("<svg onload=alert(1)>\n", 50)),
            'mp3' => $this->fx->mp3(2.0),
            default => $this->fx->bytes('.jpg', "MZ\x90\x00".str_repeat("\x00", 2048)),
        };

        try {
            $this->processor->toWebp($source, $this->fx->path('.webp'), ImageVariant::Avatar);
            self::fail('expected MediaRejected');
        } catch (MediaRejected $e) {
            self::assertSame(FailureReason::InvalidMedia, $e->reason, $e->getMessage());
        }
    }

    public function testOversizedImageIsRejected(): void
    {
        $source = $this->fx->path('.jpg');
        $h = fopen($source, 'w');
        self::assertNotFalse($h);
        ftruncate($h, ImageProcessor::MAX_SIZE_BYTES + 1);
        fclose($h);

        $this->expectExceptionObject(new MediaRejected(FailureReason::TooLarge, (ImageProcessor::MAX_SIZE_BYTES + 1).' bytes'));
        $this->processor->toWebp($source, $this->fx->path('.webp'), ImageVariant::Avatar);
    }
}
