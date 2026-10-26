<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Media\Exception\MediaRejected;
use App\Media\FfmpegRunner;
use App\Media\ImageProcessor;
use App\Media\ImageVariant;
use App\Media\TrackAudioProcessor;
use App\Tests\Media\Support\MediaFixtures;
use PHPUnit\Framework\TestCase;

/**
 * W-16: hostile media content against the worker (real ffmpeg; skipped without it).
 * Run in the worker image:
 *   docker compose run --rm --no-deps -T --entrypoint php worker vendor/bin/phpunit tests/Security/MediaSecurityTest.php.
 */
final class MediaSecurityTest extends TestCase
{
    private MediaFixtures $fx;
    private FfmpegRunner $runner;

    protected function setUp(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $this->fx = new MediaFixtures();
        $this->runner = new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin());
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    public function testUntrustedPathCannotInjectArguments(): void
    {
        $args = FfmpegRunner::inputOptions('-f lavfi -i /etc/passwd', FfmpegRunner::AUDIO_DEMUXERS);
        self::assertSame(['-protocol_whitelist', 'file', '-format_whitelist', 'mp3,mov,mp4,m4a,3gp,3g2,mj2,aac', '-i', 'file:-f lavfi -i /etc/passwd'], $args);
    }

    /** @return iterable<string, array{string, string}> */
    public static function hostileAudio(): iterable
    {
        $secret = '/etc/passwd';
        yield 'HLS playlist reading a local file' => ['.mp3', "#EXTM3U\n#EXT-X-MEDIA-SEQUENCE:0\n#EXTINF:10.0,\nfile://{$secret}\n#EXT-X-ENDLIST\n"];
        yield 'HLS playlist reaching an internal URL' => ['.m4a', "#EXTM3U\n#EXTINF:10.0,\nhttp://minio:9000/wave-private/audio/x.m4a\n#EXT-X-ENDLIST\n"];
        yield 'ffconcat script' => ['.mp3', "ffconcat version 1.0\nfile '{$secret}'\n"];
        yield 'SDP descriptor' => ['.mp3', "v=0\no=- 0 0 IN IP4 127.0.0.1\ns=x\nc=IN IP4 127.0.0.1\nt=0 0\nm=audio 5004 RTP/AVP 97\n"];
        yield 'PHP script' => ['.mp3', '<?php system($_GET["c"]); ?>'];
        yield 'subfile/concat protocol in an m3u' => ['.mp3', "#EXTM3U\n#EXTINF:1,\nconcat:{$secret}|{$secret}\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileAudio')]
    public function testHostileAudioIsRejectedWithoutReadingAnythingElse(string $suffix, string $content): void
    {
        $source = $this->fx->bytes($suffix, $content);
        $target = $this->fx->path('.m4a');
        try {
            new TrackAudioProcessor($this->runner)->process($source, $target);
            self::fail('hostile input accepted');
        } catch (MediaRejected $e) {
            self::assertStringNotContainsString('root:', $e->getMessage());
        }
        self::assertFileDoesNotExist($target);
    }

    /** ADR 009: video in MP4 is accepted but never kept; a video without audio is refused. */
    public function testMp4VideoIsDroppedAndVideoOnlyIsRejected(): void
    {
        $video = $this->fx->path('.mp4');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=s=320x240:d=3', '-f', 'lavfi', '-i', 'sine=d=3', '-c:v', 'mpeg4', '-c:a', 'aac', '-shortest', '-f', 'mp4', $video]);
        $target = $this->fx->path('.m4a');
        new TrackAudioProcessor($this->runner)->process($video, $target);
        $out = $this->fx->probe($target);
        self::assertSame(['audio'], array_values(array_unique(array_column($out['streams'] ?? [], 'codec_type'))));

        $silent = $this->fx->path('.mp4');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=s=320x240:d=2', '-c:v', 'mpeg4', '-f', 'mp4', $silent]);
        $this->expectException(MediaRejected::class);
        new TrackAudioProcessor($this->runner)->validate($silent);
    }

    public function testThousandsOfStreamsAreRejectedBeforeDecoding(): void
    {
        $many = $this->fx->path('.mp4');
        $args = ['-f', 'lavfi', '-i', 'sine=d=1', '-c:a', 'aac'];
        for ($i = 0; $i < 20; ++$i) {
            array_push($args, '-map', '0:a');
        }
        $this->ffmpeg([...$args, '-f', 'mp4', $many]);
        try {
            new TrackAudioProcessor($this->runner)->validate($many);
            self::fail('20 streams accepted');
        } catch (MediaRejected $e) {
            self::assertStringContainsString('streams', $e->getMessage());
        }
    }

    public function testWavRenamedMp3IsRejected(): void
    {
        $wav = $this->fx->path('.mp3');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'sine=d=2', '-f', 'wav', $wav]);
        $this->expectException(MediaRejected::class);
        new TrackAudioProcessor($this->runner)->validate($wav);
    }

    /** @return iterable<string, array{string}> */
    public static function hostileImages(): iterable
    {
        yield 'svg with external entity' => ['<?xml version="1.0"?><!DOCTYPE s [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg xmlns="http://www.w3.org/2000/svg"><text>&x;</text></svg>'];
        yield 'html' => ['<html><script>alert(1)</script></html>'];
        yield 'gif' => [(string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')];
        yield 'image playlist' => ["#EXTM3U\n#EXTINF:1,\nfile:///etc/passwd\n"];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hostileImages')]
    public function testHostileImagesAreRejected(string $content): void
    {
        $source = $this->fx->bytes('.png', $content);
        $this->expectException(MediaRejected::class);
        new ImageProcessor($this->runner)->toWebp($source, $this->fx->path('.webp'), ImageVariant::Avatar);
    }

    /** Header claims 30000x30000 (900 Mpx): refused from the header, before any decode. */
    public function testHugeDimensionPngIsRejectedBeforeDecoding(): void
    {
        $source = $this->fx->bytes('.png', self::pngWithHeader(30000, 30000));
        $t = microtime(true);
        try {
            new ImageProcessor($this->runner)->toWebp($source, $this->fx->path('.webp'), ImageVariant::Avatar);
            self::fail('bomb accepted');
        } catch (MediaRejected $e) {
            self::assertStringContainsString('dimensions', $e->getMessage());
        }
        self::assertLessThan(10.0, microtime(true) - $t);
    }

    /**
     * Largest image under MAX_PIXELS (7000x7000 = 49 Mpx, a few hundred KB compressed):
     * decoding must stay within the job timeout. Reports duration for capacity planning.
     */
    public function testLargestAllowedImageDecodesWithinLimits(): void
    {
        $source = $this->fx->path('.png');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=c=black:s=7000x7000', '-frames:v', '1', '-c:v', 'png', '-f', 'image2', $source]);
        self::assertLessThan(ImageProcessor::MAX_SIZE_BYTES, (int) filesize($source), 'fits the 5 MB upload limit: a compression bomb');
        $target = $this->fx->path('.webp');
        $t = microtime(true);
        new ImageProcessor($this->runner)->toWebp($source, $target, ImageVariant::Avatar);
        $elapsed = microtime(true) - $t;
        fwrite(\STDERR, \sprintf("\n[media-security] 7000x7000 PNG (%d KB) -> avatar in %.1f s\n", (int) filesize($source) / 1024, $elapsed));
        self::assertLessThan(60.0, $elapsed);
    }

    public function testPhpJpegPolyglotIsReencodedAndLosesThePayload(): void
    {
        $jpeg = (string) file_get_contents($this->fx->jpeg(64, 64));
        // Insert a COM segment carrying PHP right after SOI.
        $payload = '<?php system($_GET["c"]); ?>';
        $polyglot = substr($jpeg, 0, 2)."\xFF\xFE".pack('n', \strlen($payload) + 2).$payload.substr($jpeg, 2);
        $source = $this->fx->bytes('.jpg', $polyglot);
        $target = $this->fx->path('.webp');

        new ImageProcessor($this->runner)->toWebp($source, $target, ImageVariant::Avatar);

        $out = (string) file_get_contents($target);
        self::assertStringStartsWith('RIFF', $out);
        self::assertStringNotContainsString('<?php', $out);
    }

    public function testExifGpsIsStripped(): void
    {
        $source = $this->fx->path('.jpg');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'testsrc2=s=64x64', '-frames:v', '1', '-metadata', 'comment=GPS 43.5,5.4 secret', '-f', 'mjpeg', $source]);
        $target = $this->fx->path('.webp');
        new ImageProcessor($this->runner)->toWebp($source, $target, ImageVariant::Avatar);
        self::assertStringNotContainsString('secret', (string) file_get_contents($target));
    }

    /**
     * W-66: the 10-min limit is checked on the duration the container DECLARES. An MP3 whose
     * Xing header claims 100 frames (7.2 s) but carries 40 min of audio passes validate();
     * the passes must then stop at the limit (or reject), not transcode everything.
     */
    public function testDeclaredDurationLieCannotBypassTheTenMinuteLimit(): void
    {
        $long = $this->fx->path('.mp3');
        $this->ffmpeg(['-f', 'lavfi', '-i', 'sine=f=440:d=2400', '-ac', '1', '-ar', '8000', '-c:a', 'libmp3lame', '-b:a', '8k', '-write_xing', '1', '-f', 'mp3', $long]);
        $data = (string) file_get_contents($long);
        $tag = false !== strpos($data, 'Xing') ? 'Xing' : 'Info';
        $pos = strpos($data, $tag);
        self::assertNotFalse($pos);
        $lying = $this->fx->bytes('.mp3', substr_replace($data, pack('N', 100), $pos + 8, 4));

        $processor = new TrackAudioProcessor($this->runner);
        $declared = $processor->validate($lying);
        self::assertLessThan(60.0, (float) ($declared->audioStreams[0]['duration'] ?? $declared->durationSeconds), 'precondition: ffprobe believes the header');

        $target = $this->fx->path('.m4a');
        $t = microtime(true);
        try {
            $ms = $processor->process($lying, $target);
        } catch (MediaRejected $e) {
            self::assertNotSame('', $e->getMessage());

            return;
        }
        $elapsed = microtime(true) - $t;
        fwrite(\STDERR, \sprintf("\n[media-security] lying MP3 (declared 7 s, real 2400 s): output %d s, %.1f s of work\n", intdiv($ms, 1000), $elapsed));
        self::assertLessThanOrEqual(601_000, $ms, 'a track longer than 10 min was produced');
    }

    private static function pngWithHeader(int $w, int $h): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', \strlen($data)).$type.$data.pack('N', crc32($type.$data));
        $row = "\0".str_repeat("\0", 3 * 16);
        $idat = (string) gzcompress(str_repeat($row, 16));

        return "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0)).$chunk('IDAT', $idat).$chunk('IEND', '');
    }

    /** @param list<string> $args */
    private function ffmpeg(array $args): void
    {
        $p = new \Symfony\Component\Process\Process([MediaFixtures::ffmpegBin(), '-hide_banner', '-loglevel', 'error', '-y', ...$args]);
        $p->setTimeout(120);
        $p->mustRun();
    }
}
