<?php

declare(strict_types=1);

namespace App\Tests\Media\Support;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Synthesized media for tests (BRIEF: generated audio only, never copyrighted
 * files). Requires ffmpeg: run in the worker image (`make test-media`).
 */
final class MediaFixtures
{
    /** @var list<string> */
    private array $files = [];

    public static function ffmpegBin(): string
    {
        return getenv('FFMPEG_BIN') ?: '/usr/bin/ffmpeg';
    }

    public static function ffprobeBin(): string
    {
        return getenv('FFPROBE_BIN') ?: '/usr/bin/ffprobe';
    }

    public static function skipUnlessFfmpeg(TestCase $test): void
    {
        if (!is_executable(self::ffmpegBin()) || !is_executable(self::ffprobeBin())) {
            $test->markTestSkipped('ffmpeg/ffprobe not available (run `make test-media` in the worker image).');
        }
    }

    public function path(string $suffix): string
    {
        $path = \sprintf('%s/wave-test-%s%s', sys_get_temp_dir(), bin2hex(random_bytes(8)), $suffix);
        $this->files[] = $path;

        return $path;
    }

    /**
     * Two-tone synthesized music-like signal (aevalsrc) at a given amplitude.
     *
     * @param string $codecArgs e.g. "-c:a libmp3lame -b:a 192k"
     */
    public function audio(string $suffix, float $seconds, float $amplitude, string $codecArgs, string $format): string
    {
        $out = $this->path($suffix);
        $expr = \sprintf('%1$F*sin(2*PI*220*t)*(0.6+0.4*sin(2*PI*0.5*t))|%1$F*sin(2*PI*330*t)*(0.6+0.4*cos(2*PI*0.5*t))', $amplitude);
        $this->ffmpeg(array_merge(
            ['-f', 'lavfi', '-i', \sprintf('aevalsrc=%s:s=44100:d=%F', $expr, $seconds)],
            preg_split('/\s+/', trim($codecArgs)) ?: [],
            ['-f', $format, $out],
        ));

        return $out;
    }

    public function mp3(float $seconds = 20.0, float $amplitude = 0.3): string
    {
        return $this->audio('.mp3', $seconds, $amplitude, '-c:a libmp3lame -b:a 192k', 'mp3');
    }

    public function m4a(float $seconds = 20.0, float $amplitude = 0.3): string
    {
        return $this->audio('.m4a', $seconds, $amplitude, '-c:a aac -b:a 128k', 'mp4');
    }

    /** A still image of the given size (codec chosen by the format). */
    public function image(string $suffix, int $width, int $height, string $format, string $codec): string
    {
        $out = $this->path($suffix);
        $this->ffmpeg(['-f', 'lavfi', '-i', \sprintf('testsrc2=s=%dx%d', $width, $height), '-frames:v', '1', '-c:v', $codec, '-f', $format, $out]);

        return $out;
    }

    public function png(int $width = 640, int $height = 480): string
    {
        return $this->image('.png', $width, $height, 'image2', 'png');
    }

    public function jpeg(int $width = 1200, int $height = 900): string
    {
        return $this->image('.jpg', $width, $height, 'image2', 'mjpeg');
    }

    public function webp(int $width = 500, int $height = 300): string
    {
        return $this->image('.webp', $width, $height, 'webp', 'libwebp');
    }

    /** Arbitrary bytes with a given name (e.g. a fake Windows executable). */
    public function bytes(string $suffix, string $content): string
    {
        $out = $this->path($suffix);
        file_put_contents($out, $content);

        return $out;
    }

    /** @return array<string, mixed> */
    public function probe(string $path): array
    {
        $p = new Process([self::ffprobeBin(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path]);
        $p->mustRun();
        $json = json_decode($p->getOutput(), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($json));

        /* @var array<string, mixed> $json */
        return $json;
    }

    /** Integrated loudness (LUFS) measured by the ebur128 filter. */
    public function integratedLoudness(string $path): float
    {
        $p = new Process([self::ffmpegBin(), '-hide_banner', '-nostats', '-i', $path, '-af', 'ebur128', '-f', 'null', '-']);
        $p->setTimeout(120);
        $p->mustRun();
        // The last "I: x LUFS" is the summary line.
        if (0 === preg_match_all('/I:\s+(-?[\d.]+) LUFS/', $p->getErrorOutput(), $m)) {
            throw new \RuntimeException('no loudness in ebur128 output');
        }

        return (float) $m[1][\count($m[1]) - 1];
    }

    public function cleanup(): void
    {
        foreach ($this->files as $f) {
            @unlink($f);
        }
        $this->files = [];
    }

    /** @param list<string> $args */
    private function ffmpeg(array $args): void
    {
        $p = new Process([self::ffmpegBin(), '-hide_banner', '-v', 'error', '-y', ...$args]);
        $p->setTimeout(120);
        $p->mustRun();
    }
}
