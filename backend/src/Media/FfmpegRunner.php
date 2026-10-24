<?php

declare(strict_types=1);

namespace App\Media;

use App\Enum\FailureReason;
use App\Media\Exception\MediaRejected;
use App\Media\Exception\MediaToolFailed;
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

    private readonly string $ffmpegBin;
    private readonly string $ffprobeBin;

    public function __construct(
        #[Autowire('%env(default::FFMPEG_BIN)%')]
        ?string $ffmpegBin = null,
        #[Autowire('%env(default::FFPROBE_BIN)%')]
        ?string $ffprobeBin = null,
    ) {
        $this->ffmpegBin = $ffmpegBin ?: '/usr/bin/ffmpeg';
        $this->ffprobeBin = $ffprobeBin ?: '/usr/bin/ffprobe';
    }

    /**
     * @param list<string> $allowedDemuxers
     *
     * @throws MediaRejected   (invalid_media) when ffprobe cannot read the file with an allowed demuxer
     * @throws MediaToolFailed                 on timeout or unusable ffprobe output
     */
    public function probe(string $path, array $allowedDemuxers): Probe
    {
        $process = new Process([
            $this->ffprobeBin, '-v', 'error', '-hide_banner',
            ...self::inputOptions($path, $allowedDemuxers),
            '-print_format', 'json', '-show_format', '-show_streams',
        ]);
        $process->setTimeout(self::PROBE_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MediaToolFailed('ffprobe timed out', 0, $e);
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
     * Runs ffmpeg with the given arguments (without the binary) and returns stderr.
     *
     * @param list<string> $args
     *
     * @throws MediaToolFailed on non-zero exit or timeout
     */
    public function ffmpeg(array $args, float $timeoutSeconds): string
    {
        $process = new Process([$this->ffmpegBin, '-hide_banner', '-nostdin', '-y', ...$args]);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MediaToolFailed(\sprintf('ffmpeg timed out after %d s', (int) $timeoutSeconds), 0, $e);
        }

        if (!$process->isSuccessful()) {
            throw new MediaToolFailed(\sprintf('ffmpeg exited with %d: %s', $process->getExitCode() ?? -1, self::lastLine($process->getErrorOutput())));
        }

        return $process->getErrorOutput();
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

    private static function lastLine(string $output): string
    {
        $lines = preg_split('/\R/', trim($output)) ?: [];

        return mb_substr((string) end($lines), 0, 300);
    }
}
