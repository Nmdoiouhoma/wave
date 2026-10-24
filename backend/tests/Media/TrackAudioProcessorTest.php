<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\FfmpegRunner;
use App\Media\TrackAudioProcessor;
use App\Tests\Media\Support\MediaFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * W-10 ProcessTrack AC, with real ffmpeg on synthesized audio.
 */
final class TrackAudioProcessorTest extends TestCase
{
    private MediaFixtures $fx;
    private TrackAudioProcessor $processor;

    protected function setUp(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $this->fx = new MediaFixtures();
        $this->processor = new TrackAudioProcessor(new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin()));
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    /**
     * @return iterable<string, array{string, float}>
     */
    public static function acceptedInputs(): iterable
    {
        yield 'loud mp3' => ['mp3', 0.8];
        yield 'quiet mp3' => ['mp3', 0.02];
        yield 'aac m4a' => ['m4a', 0.3];
    }

    #[DataProvider('acceptedInputs')]
    public function testTranscodesToNormalizedAacM4a(string $kind, float $amplitude): void
    {
        $source = 'mp3' === $kind ? $this->fx->mp3(20.0, $amplitude) : $this->fx->m4a(20.0, $amplitude);
        $target = $this->fx->path('.m4a');

        $durationMs = $this->processor->process($source, $target);

        self::assertEqualsWithDelta(20_000, $durationMs, 150);

        $probe = $this->fx->probe($target);
        self::assertIsArray($probe['streams']);
        self::assertCount(1, $probe['streams'], 'audio only, cover art and metadata stripped');
        $stream = $probe['streams'][0];
        self::assertIsArray($stream);
        self::assertSame('aac', $stream['codec_name']);
        self::assertSame('LC', $stream['profile']);
        self::assertSame('44100', $stream['sample_rate']);
        self::assertSame(2, $stream['channels']);
        self::assertEqualsWithDelta(160_000, (int) $stream['bit_rate'], 20_000);
        self::assertIsArray($probe['format']);
        self::assertStringContainsString('mp4', (string) $probe['format']['format_name']);

        // +faststart: the moov atom precedes mdat.
        $head = (string) file_get_contents($target, false, null, 0, 64 * 1024);
        $moov = strpos($head, 'moov');
        self::assertNotFalse($moov, 'moov atom in the first 64 KiB');
        $mdat = strpos($head, 'mdat');
        self::assertTrue(false === $mdat || $moov < $mdat, 'moov before mdat');

        self::assertEqualsWithDelta(-14.0, $this->fx->integratedLoudness($target), 1.0, 'integrated loudness -14 ± 1 LUFS');
    }

    public function testElevenMinuteFileIsTooLong(): void
    {
        $source = $this->fx->audio('.mp3', 660.0, 0.3, '-c:a libmp3lame -b:a 32k -ac 1', 'mp3');

        $this->assertRejected(FailureReason::TooLong, $source);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidFiles(): iterable
    {
        yield 'renamed exe' => ['.exe', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xff\xff\x00\x00".str_repeat("\x00", 4096).'This program cannot be run in DOS mode.'];
        yield 'text' => ['.mp3', str_repeat("hello world\n", 200)];
        yield 'empty' => ['.mp3', ''];
        yield 'hls playlist disguised as mp3' => ['.mp3', "#EXTM3U\n#EXT-X-TARGETDURATION:1\n#EXTINF:1,\nfile:/etc/passwd\n#EXT-X-ENDLIST\n"];
    }

    #[DataProvider('invalidFiles')]
    public function testGarbageIsInvalidMedia(string $suffix, string $content): void
    {
        $this->assertRejected(FailureReason::InvalidMedia, $this->fx->bytes($suffix, $content));
    }

    public function testPngRenamedToMp3IsInvalidMedia(): void
    {
        $png = $this->fx->png();
        $renamed = $this->fx->path('.mp3');
        copy($png, $renamed);

        $this->assertRejected(FailureReason::InvalidMedia, $renamed);
    }

    public function testVideoIsInvalidMedia(): void
    {
        $video = $this->fx->path('.mp4');
        (new \Symfony\Component\Process\Process([
            MediaFixtures::ffmpegBin(), '-v', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc2=s=320x240:d=3', '-f', 'lavfi', '-i', 'sine=d=3',
            '-c:v', 'libx264', '-c:a', 'aac', '-shortest', $video,
        ]))->mustRun();

        $this->assertRejected(FailureReason::InvalidMedia, $video);
    }

    public function testOtherCodecInAcceptedContainerIsUnsupported(): void
    {
        // ALAC in an .m4a: the container is fine, the codec is not MP3/AAC.
        $source = $this->fx->audio('.m4a', 3.0, 0.3, '-c:a alac', 'mp4');

        $this->assertRejected(FailureReason::UnsupportedCodec, $source);
    }

    public function testWavIsRejected(): void
    {
        $source = $this->fx->audio('.wav', 3.0, 0.3, '-c:a pcm_s16le', 'wav');

        // The wav demuxer is not whitelisted: ffprobe refuses it.
        $this->assertRejected(FailureReason::InvalidMedia, $source);
    }

    public function testOversizedFileIsTooLarge(): void
    {
        $source = $this->fx->path('.mp3');
        $h = fopen($source, 'w');
        self::assertNotFalse($h);
        ftruncate($h, TrackAudioProcessor::MAX_SIZE_BYTES + 1);
        fclose($h);

        $this->assertRejected(FailureReason::TooLarge, $source);
    }

    public function testSilenceIsEncodedWithoutLoudnorm(): void
    {
        $source = $this->fx->mp3(5.0, 0.0);
        $target = $this->fx->path('.m4a');

        self::assertEqualsWithDelta(5_000, $this->processor->process($source, $target), 150);
    }

    public function testParseLoudnormJsonRejectsInfinity(): void
    {
        $stderr = "[Parsed_loudnorm_0 @ 0x1]\n{\n\t\"input_i\" : \"-inf\",\n\t\"input_tp\" : \"-inf\",\n\t\"input_lra\" : \"0.00\",\n\t\"input_thresh\" : \"-inf\",\n\t\"target_offset\" : \"inf\"\n}\n";

        self::assertNull(TrackAudioProcessor::parseLoudnormJson($stderr));
        self::assertSame(
            ['input_i' => -20.5, 'input_tp' => -3.1, 'input_lra' => 4.0, 'input_thresh' => -30.6, 'target_offset' => 0.2],
            TrackAudioProcessor::parseLoudnormJson('{"input_i":"-20.5","input_tp":"-3.1","input_lra":"4.0","input_thresh":"-30.6","target_offset":"0.2"}'),
        );
    }

    private function assertRejected(FailureReason $expected, string $source): void
    {
        try {
            $this->processor->process($source, $this->fx->path('.m4a'));
            self::fail('expected MediaRejected '.$expected->value);
        } catch (MediaRejected $e) {
            self::assertSame($expected, $e->reason, $e->getMessage());
        }
    }
}
