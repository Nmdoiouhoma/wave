<?php

declare(strict_types=1);

namespace App\Tests\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;
use App\Media\Exception\MediaToolTimedOut;
use App\Media\FfmpegRunner;
use App\Tests\Media\Support\MediaFixtures;
use PHPUnit\Framework\TestCase;

/**
 * W-66 F1/F4: ffmpeg/ffprobe run under rlimits and report the real decoded time.
 */
final class FfmpegRunnerTest extends TestCase
{
    private MediaFixtures $fx;

    protected function setUp(): void
    {
        MediaFixtures::skipUnlessFfmpeg($this);
        $this->fx = new MediaFixtures();
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    public function testRunReportsTheDecodedDuration(): void
    {
        $runner = new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin());
        $mp3 = $this->fx->mp3(12.0);

        $result = $runner->run(['-t', '5', ...FfmpegRunner::inputOptions($mp3, FfmpegRunner::AUDIO_DEMUXERS), '-f', 'null', '-'], 60);

        self::assertEqualsWithDelta(5.0, (float) $result['outTimeSeconds'], 0.1, '-t before -i caps what is read');
    }

    public function testAddressSpaceLimitIsEnforced(): void
    {
        if (!is_executable('/usr/bin/prlimit')) {
            self::markTestSkipped('prlimit not available (worker image only)');
        }
        // 64 MB is far below what ffmpeg/ffprobe need: proves the rlimit is applied
        // (a huge moov table cannot take the worker's 1 GB).
        $runner = new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin(), 64 * 1024 * 1024, 64 * 1024 * 1024);
        $mp3 = $this->fx->mp3(2.0);

        try {
            $runner->probe($mp3, FfmpegRunner::AUDIO_DEMUXERS);
            self::fail('ffprobe ran above its address-space limit');
        } catch (MediaRejected $e) {
            self::assertSame(FailureReason::InvalidMedia, $e->reason);
        }

        $this->expectException(MediaToolFailed::class);
        $runner->run([...FfmpegRunner::inputOptions($mp3, FfmpegRunner::AUDIO_DEMUXERS), '-f', 'null', '-'], 60);
    }

    public function testWallClockTimeoutIsDistinguishable(): void
    {
        $runner = new FfmpegRunner(MediaFixtures::ffmpegBin(), MediaFixtures::ffprobeBin());

        $this->expectException(MediaToolTimedOut::class);
        // Real-time read (-re) of a 60 s source with a 1 s budget.
        $runner->run(['-re', '-f', 'lavfi', '-i', 'sine=d=60', '-f', 'null', '-'], 1.0);
    }
}
