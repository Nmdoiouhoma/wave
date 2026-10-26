<?php

declare(strict_types=1);

namespace App\Media;

/**
 * Subset of `ffprobe -show_format -show_streams` output used for validation.
 */
final readonly class Probe
{
    /**
     * @param list<string>                                                           $formatNames  demuxer names, e.g. ['mov','mp4','m4a',...]
     * @param list<array{codec: string, duration: float|null}>                       $audioStreams
     * @param list<array{codec: string, width: int, height: int, attachedPic: bool}> $videoStreams
     */
    public function __construct(
        public array $formatNames,
        public ?float $durationSeconds,
        public array $audioStreams,
        public array $videoStreams,
        public int $streamCount = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $json decoded ffprobe JSON
     */
    public static function fromFfprobeJson(array $json): self
    {
        $format = \is_array($json['format'] ?? null) ? $json['format'] : [];
        $formatName = \is_string($format['format_name'] ?? null) ? $format['format_name'] : '';
        $duration = isset($format['duration']) && is_numeric($format['duration']) ? (float) $format['duration'] : null;

        $audio = [];
        $video = [];
        $streams = \is_array($json['streams'] ?? null) ? $json['streams'] : [];
        foreach ($streams as $stream) {
            if (!\is_array($stream)) {
                continue;
            }
            $codec = \is_string($stream['codec_name'] ?? null) ? $stream['codec_name'] : '';
            $type = $stream['codec_type'] ?? null;
            if ('audio' === $type) {
                $audio[] = [
                    'codec' => $codec,
                    'duration' => isset($stream['duration']) && is_numeric($stream['duration']) ? (float) $stream['duration'] : null,
                ];
                if (null === $duration && isset($stream['duration']) && is_numeric($stream['duration'])) {
                    $duration = (float) $stream['duration'];
                }
            } elseif ('video' === $type) {
                $disposition = \is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];
                $video[] = [
                    'codec' => $codec,
                    'width' => \is_int($stream['width'] ?? null) ? $stream['width'] : 0,
                    'height' => \is_int($stream['height'] ?? null) ? $stream['height'] : 0,
                    'attachedPic' => 1 === ($disposition['attached_pic'] ?? 0),
                ];
            }
        }

        return new self(
            '' === $formatName ? [] : explode(',', $formatName),
            $duration,
            $audio,
            $video,
            \count($streams),
        );
    }

    /** True for the ISO-BMFF family (MP4/M4A/MOV/3GP), whatever the file name. */
    public function isMp4Family(): bool
    {
        return $this->hasFormat('mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2');
    }

    public function hasFormat(string ...$names): bool
    {
        return [] !== array_intersect($this->formatNames, $names);
    }
}
