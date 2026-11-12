<?php

declare(strict_types=1);

namespace App\Command\Seed;

use Symfony\Component\Process\Process;

/** Dev seed only: generated avatars, covers and post visuals (FFmpeg gradients, no photo, no external file). */
final class ImageSynth
{
    private const string FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    /** @var list<array{string, string, string}> */
    private const array PALETTES = [
        ['0xFF6B6B', '0xFFD93D', '0x6BCB77'],
        ['0x4D96FF', '0x9D4EDD', '0xFF6B9D'],
        ['0x06D6A0', '0x118AB2', '0x073B4C'],
        ['0xF72585', '0x7209B7', '0x3A0CA3'],
        ['0xFF9F1C', '0xF15BB5', '0x00BBF9'],
        ['0x2EC4B6', '0xFFBF69', '0xFF5964'],
        ['0x8338EC', '0x3A86FF', '0x06D6A0'],
        ['0xEF476F', '0xFFD166', '0x26547C'],
    ];

    public function __construct(private readonly string $ffmpeg)
    {
    }

    public function avatar(string $label, int $seed, string $out): void
    {
        $this->run($this->base($seed, '512x512', 1).$this->text($label, 260, 0), $out, ['-frames:v', '1']);
    }

    public function cover(string $label, int $seed, string $out): void
    {
        $this->run($this->base($seed, '800x800', 1).$this->text($label, 70, 0), $out, ['-frames:v', '1']);
    }

    public function postImage(string $label, int $seed, string $out): void
    {
        $this->run($this->base($seed, '1080x1350', 1).$this->text($label, 64, 0), $out, ['-frames:v', '1']);
    }

    /** 5 s, 720x1280, H.264 main, no audio track: what a phone would upload. */
    public function postVideo(int $seed, string $out): void
    {
        $this->run($this->base($seed, '720x1280', 5, speed: 0.08), $out, ['-c:v', 'libx264', '-profile:v', 'main', '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-crf', '28', '-an', '-movflags', '+faststart']);
    }

    private function base(int $seed, string $size, int $duration, float $speed = 0.01): string
    {
        [$c0, $c1, $c2] = self::PALETTES[$seed % \count(self::PALETTES)];

        return \sprintf('gradients=s=%s:c0=%s:c1=%s:c2=%s:nb_colors=3:seed=%d:speed=%s:r=24:d=%d', $size, $c0, $c1, $c2, $seed, (string) $speed, $duration);
    }

    private function text(string $label, int $size, int $unused): string
    {
        $clean = trim((string) preg_replace('/[^\p{L}\p{N} ]/u', '', $label));
        if ('' === $clean) {
            return '';
        }

        return ',drawtext=fontfile='.self::FONT.":text={$clean}:fontsize={$size}:fontcolor=white@0.9:shadowx=3:shadowy=3:x=(w-text_w)/2:y=(h-text_h)/2";
    }

    /** @param list<string> $outArgs */
    private function run(string $filter, string $out, array $outArgs): void
    {
        $p = new Process([$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostats', '-y', '-f', 'lavfi', '-i', $filter, ...$outArgs, $out], null, null, null, 120.0);
        $p->run();
        if (!$p->isSuccessful()) {
            throw new \RuntimeException('image synthesis failed: '.$p->getErrorOutput());
        }
    }
}
