<?php

declare(strict_types=1);

namespace App\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;
use App\Media\Exception\MediaToolTimedOut;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Thin wrapper around the ffmpeg/ffprobe binaries (worker container only).
 *
 * Every untrusted input is opened with:
 *  - `-protocol_whitelist file` and a `file:` path: no network, no concat:/subfile: tricks;
 *  - `-format_whitelist <demuxers>`: a playlist (hls, concat...) disguised as an
 *    upload cannot make ffmpeg read other local files or reach internal hosts.
 */
final class FfmpegRunner
{
    public const AUDIO_DEMUXERS = ['mp3', 'mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2', 'aac'];
    public const IMAGE_DEMUXERS = ['image2', 'png_pipe', 'jpeg_pipe', 'webp_pipe'];

    private const PROBE_TIMEOUT_SECONDS = 30.0;

    /**
     * Per-process rlimits (prlimit), on top of the wall-clock timeouts: a
     * crafted file (huge moov table, decompression bomb) cannot take the
     * worker's 1 GB or loop on CPU (W-66 F1/F4). ffmpeg needs ~300 MB of
     * address space just for its libraries and thread stacks.
     */
    public const FFMPEG_MAX_ADDRESS_SPACE = 768 * 1024 * 1024;
    public const FFPROBE_MAX_ADDRESS_SPACE = 512 * 1024 * 1024;
    private const PRLIMIT_BIN = '/usr/bin/prlimit';

    private readonly string $ffmpegBin;
    private readonly string $ffprobeBin;
    private readonly int $ffmpegMaxAddressSpace;
    private readonly int $ffprobeMaxAddressSpace;

    public function __construct(
        #[Autowire('%env(default::FFMPEG_BIN)%')]
        ?string $ffmpegBin = null,
        #[Autowire('%env(default::FFPROBE_BIN)%')]
        ?string $ffprobeBin = null,
        ?int $ffmpegMaxAddressSpace = null,
        ?int $ffprobeMaxAddressSpace = null,
    ) {
        $this->ffmpegBin = $ffmpegBin ?: '/usr/bin/ffmpeg';
        $this->ffprobeBin = $ffprobeBin ?: '/usr/bin/ffprobe';
        $this->ffmpegMaxAddressSpace = $ffmpegMaxAddressSpace ?? self::FFMPEG_MAX_ADDRESS_SPACE;
        $this->ffprobeMaxAddressSpace = $ffprobeMaxAddressSpace ?? self::FFPROBE_MAX_ADDRESS_SPACE;
    }

    /**
     * @param list<string> $allowedDemuxers
     *
     * @throws MediaRejected   (invalid_media) when ffprobe cannot read the file with an allowed demuxer, or times out
     * @throws MediaToolFailed                 on unusable ffprobe output
     */
    public function probe(string $path, array $allowedDemuxers): Probe
    {
        $process = new Process(self::limited([
            $this->ffprobeBin, '-v', 'error', '-hide_banner',
            ...self::inputOptions($path, $allowedDemuxers),
            '-print_format', 'json', '-show_format', '-show_streams',
        ], $this->ffprobeMaxAddressSpace, self::PROBE_TIMEOUT_SECONDS));
        $process->setTimeout(self::PROBE_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            // Probing reads headers only: 30 s means a hostile file. Final, no retry.
            throw new MediaRejected(FailureReason::InvalidMedia, 'ffprobe timed out', $e);
        }

        if (!$process->isSuccessful()) {
            throw new MediaRejected(FailureReason::InvalidMedia, 'ffprobe: '.self::lastLine($process->getErrorOutput()));
        }

        try {
            $json = json_decode($process->getOutput(), true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MediaToolFailed('ffprobe returned invalid JSON', 0, $e);
        }
        if (!\is_array($json)) {
            throw new MediaToolFailed('ffprobe returned unexpected JSON');
        }

        /* @var array<string, mixed> $json */
        return Probe::fromFfprobeJson($json);
    }

    /**
     * Runs ffmpeg with the given arguments (without the binary) and returns
     * stderr; `outTimeSeconds` is the media time actually written (from
     * `-progress`), i.e. the real decoded duration, whatever the headers claim.
     *
     * @param list<string> $args
     *
     * @return array{stderr: string, outTimeSeconds: float|null}
     *
     * @throws MediaToolTimedOut on wall-clock timeout or CPU rlimit
     * @throws MediaToolFailed   on any other non-zero exit
     */
    public function run(array $args, float $timeoutSeconds): array
    {
        $process = new Process(self::limited(
            [$this->ffmpegBin, '-hide_banner', '-nostdin', '-y', '-progress', 'pipe:1', '-nostats', ...$args],
            $this->ffmpegMaxAddressSpace,
            $timeoutSeconds,
        ));
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MediaToolTimedOut(\sprintf('ffmpeg timed out after %d s', (int) $timeoutSeconds), 0, $e);
        }

        if (!$process->isSuccessful()) {
            $message = \sprintf('ffmpeg exited with %d: %s', $process->getExitCode() ?? -1, self::lastLine($process->getErrorOutput()));
            // SIGXCPU (24) / SIGKILL (9) after the CPU-seconds rlimit.
            if (\in_array($process->getTermSignal(), [9, 24], true) || \in_array($process->getExitCode(), [128 + 9, 128 + 24], true)) {
                throw new MediaToolTimedOut('ffmpeg CPU limit: '.$message);
            }
            throw new MediaToolFailed($message);
        }

        $outTime = null;
        if (preg_match_all('/^out_time_us=(\d+)$/m', $process->getOutput(), $m) > 0) {
            $outTime = (int) end($m[1]) / 1_000_000;
        }

        return ['stderr' => $process->getErrorOutput(), 'outTimeSeconds' => $outTime];
    }

    /**
     * @see run() — stderr only.
     *
     * @param list<string> $args
     */
    public function ffmpeg(array $args, float $timeoutSeconds): string
    {
        return $this->run($args, $timeoutSeconds)['stderr'];
    }

    /**
     * Options that must precede `-i` for any untrusted input.
     *
     * @param list<string> $allowedDemuxers
     *
     * @return list<string>
     */
    public static function inputOptions(string $path, array $allowedDemuxers): array
    {
        return [
            '-protocol_whitelist', 'file',
            '-format_whitelist', implode(',', $allowedDemuxers),
            '-i', 'file:'.$path,
        ];
    }

    /**
     * Wraps a command with prlimit: address space and CPU seconds (2x the wall
     * timeout: ffmpeg is multi-threaded). Skipped if prlimit is missing (host
     * runs outside the worker image).
     *
     * @param list<string> $command
     *
     * @return list<string>
     */
    private static function limited(array $command, int $maxAddressSpace, float $timeoutSeconds): array
    {
        if (!is_executable(self::PRLIMIT_BIN)) {
            return $command;
        }

        return [
            self::PRLIMIT_BIN,
            '--as='.$maxAddressSpace,
            '--cpu='.((int) ceil($timeoutSeconds) * 2),
            '--core=0',
            '--',
            ...$command,
        ];
    }

    private static function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        return mb_substr((string) end($lines), 0, 300);
    }
}
