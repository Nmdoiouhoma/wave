<?php

declare(strict_types=1);

namespace App\Command\Seed;

use Symfony\Component\Process\Process;

/**
 * Dev seed only: synthesizes a small "song" with FFmpeg (`aevalsrc`, no external file, nothing copyrighted).
 *
 * Each archetype has its own drum patterns, bass, lead timbre, scale and tempo range; every track adds
 * its own tempo, key, chord progression and two melody patterns drawn from a seeded generator. Four
 * short sections (intro, groove, chorus, break) are rendered once, then repeated in a song structure up
 * to the requested duration, so a 4-minute track costs about as much as a 40-second one.
 */
final class AudioSynth
{
    private const int SAMPLE_RATE = 22_050;
    private const int BARS_PER_SECTION = 4;

    /** Semitone offsets of scale degrees 1..7 (one hex digit each in the expression). */
    private const array SCALES = [
        'minor' => [0, 2, 3, 5, 7, 8, 10],
        'major' => [0, 2, 4, 5, 7, 9, 11],
        'dorian' => [0, 2, 3, 5, 7, 9, 10],
        'phrygian' => [0, 1, 3, 5, 7, 8, 10],
        'pentatonic' => [0, 3, 5, 7, 10, 12, 15],
        'majpent' => [0, 2, 4, 7, 9, 12, 14],
    ];

    /** Chord roots (semitones) per bar, four bars. */
    private const array PROGRESSIONS = [
        'minor' => [[0, 8, 3, 10], [0, 5, 8, 7], [0, 3, 8, 10], [0, 0, 5, 7]],
        'major' => [[0, 7, 9, 5], [0, 5, 7, 5], [0, 9, 5, 7], [0, 4, 9, 7]],
    ];

    /**
     * kick/snare/hat/bass: 16-step patterns ('x' = hit). lead: probability of a note per step.
     * Partials: [ratio, weight]. kd/bd/ld: envelope decay rates relative to one step (bigger = shorter).
     *
     * @var array<string, array<string, mixed>>
     */
    private const array ARCHETYPES = [
        'boombap' => ['bpm' => [82, 96], 'kick' => 'x......x..x.....', 'snare' => '....x.......x...', 'hat' => 'x.x.x.x.x.x.x.x.', 'bass' => 'x......x..x.....', 'scales' => ['dorian', 'pentatonic', 'minor'], 'lead' => 'pluck', 'leadP' => 0.35, 'bass_t' => 'sub', 'pad' => 0.10, 'swing' => 0],
        'trap' => ['bpm' => [132, 148], 'kick' => 'x.......x.x.....', 'snare' => '........x.......', 'hat' => 'xxxxxxxxxxxxxxxx', 'bass' => 'x.......x.x.....', 'scales' => ['minor', 'phrygian', 'pentatonic'], 'lead' => 'bell', 'leadP' => 0.3, 'bass_t' => '808', 'pad' => 0.06, 'swing' => 0],
        'drill' => ['bpm' => [138, 144], 'kick' => 'x..x..x.x.x.xx.x', 'snare' => '....x.......x...', 'hat' => 'x.x.xxx.x.x.xxx.', 'bass' => 'x..x......x.x...', 'scales' => ['phrygian', 'minor'], 'lead' => 'bell', 'leadP' => 0.25, 'bass_t' => '808', 'pad' => 0.05, 'swing' => 0],
        'fourfloor' => ['bpm' => [120, 128], 'kick' => 'x...x...x...x...', 'snare' => '....x.......x...', 'hat' => '..x...x...x...x.', 'bass' => '..x...x...x...x.', 'scales' => ['minor', 'dorian'], 'lead' => 'saw', 'leadP' => 0.4, 'bass_t' => 'saw', 'pad' => 0.12, 'swing' => 0],
        'techno' => ['bpm' => [130, 140], 'kick' => 'x...x...x...x...', 'snare' => '', 'hat' => 'xxxxxxxxxxxxxxxx', 'bass' => 'x.xxx.xxx.xxx.xx', 'scales' => ['phrygian', 'minor'], 'lead' => 'square', 'leadP' => 0.18, 'bass_t' => 'saw', 'pad' => 0.08, 'swing' => 0],
        'dnb' => ['bpm' => [168, 176], 'kick' => 'x.........x.....', 'snare' => '....x.......x...', 'hat' => 'x.x.x.x.x.x.x.x.', 'bass' => 'x.....x...x.....', 'scales' => ['minor', 'dorian'], 'lead' => 'saw', 'leadP' => 0.3, 'bass_t' => 'saw', 'pad' => 0.12, 'swing' => 0],
        'afrobeats' => ['bpm' => [98, 106], 'kick' => 'x.....x...x.....', 'snare' => '...x..x...x..x..', 'hat' => 'x.xxx.xxx.xxx.xx', 'bass' => 'x..x..x...x..x..', 'scales' => ['majpent', 'major', 'dorian'], 'lead' => 'pluck', 'leadP' => 0.5, 'bass_t' => 'sub', 'pad' => 0.08, 'swing' => 0],
        'amapiano' => ['bpm' => [110, 114], 'kick' => 'x...x...x...x...', 'snare' => '', 'hat' => '..x...x...x...x.', 'bass' => 'x..x..x.x..x..x.', 'scales' => ['minor', 'dorian'], 'lead' => 'epiano', 'leadP' => 0.4, 'bass_t' => 'log', 'pad' => 0.2, 'swing' => 0],
        'dembow' => ['bpm' => [92, 98], 'kick' => 'x...x...x...x...', 'snare' => '...x..x....x..x.', 'hat' => 'x.x.x.x.x.x.x.x.', 'bass' => 'x..x..x.x..x..x.', 'scales' => ['minor', 'phrygian'], 'lead' => 'saw', 'leadP' => 0.3, 'bass_t' => 'sub', 'pad' => 0.08, 'swing' => 0],
        'lofi' => ['bpm' => [70, 84], 'kick' => 'x.....x...x.....', 'snare' => '....x.......x...', 'hat' => 'x.x.x.x.x.x.x.x.', 'bass' => 'x.......x.....x.', 'scales' => ['dorian', 'pentatonic'], 'lead' => 'epiano', 'leadP' => 0.45, 'bass_t' => 'sub', 'pad' => 0.22, 'swing' => 0, 'vinyl' => true, 'soft' => true],
        'ballad' => ['bpm' => [64, 76], 'kick' => 'x.......x.......', 'snare' => '', 'hat' => '', 'bass' => 'x.......x.......', 'scales' => ['major', 'minor'], 'lead' => 'epiano', 'leadP' => 0.6, 'bass_t' => 'sub', 'pad' => 0.28, 'swing' => 0, 'soft' => true],
        'ambient' => ['bpm' => [60, 68], 'kick' => '', 'snare' => '', 'hat' => '', 'bass' => 'x...............', 'scales' => ['pentatonic', 'major', 'dorian'], 'lead' => 'bell', 'leadP' => 0.12, 'bass_t' => 'sub', 'pad' => 0.45, 'swing' => 0],
        'rock' => ['bpm' => [112, 134], 'kick' => 'x.....x...x.....', 'snare' => '....x.......x...', 'hat' => 'x.x.x.x.x.x.x.x.', 'bass' => 'x.x.x.x.x.x.x.x.', 'scales' => ['minor', 'pentatonic'], 'lead' => 'square', 'leadP' => 0.4, 'bass_t' => 'saw', 'pad' => 0.05, 'swing' => 0],
        'jazz' => ['bpm' => [100, 126], 'kick' => 'x.......x.......', 'snare' => '', 'hat' => 'x.xxx.xxx.xxx.xx', 'bass' => 'x...x...x...x...', 'scales' => ['dorian', 'major'], 'lead' => 'epiano', 'leadP' => 0.55, 'bass_t' => 'sub', 'pad' => 0.1, 'swing' => 0, 'soft' => true],
        'coupe' => ['bpm' => [124, 130], 'kick' => 'x.x...x.x.x...x.', 'snare' => '....x.......x.x.', 'hat' => 'xxxxxxxxxxxxxxxx', 'bass' => 'x..x..x..x..x...', 'scales' => ['majpent', 'major'], 'lead' => 'pluck', 'leadP' => 0.55, 'bass_t' => 'sub', 'pad' => 0.06, 'swing' => 0],
        'pop' => ['bpm' => [100, 120], 'kick' => 'x...x...x...x...', 'snare' => '....x.......x...', 'hat' => 'x.x.x.x.x.x.x.x.', 'bass' => 'x.x.x.x.x.x.x.x.', 'scales' => ['major', 'minor'], 'lead' => 'saw', 'leadP' => 0.45, 'bass_t' => 'sub', 'pad' => 0.14, 'swing' => 0],
        'rnb' => ['bpm' => [62, 80], 'kick' => 'x.....x..x......', 'snare' => '....x.......x...', 'hat' => 'xxxxxxxxxxxxxxxx', 'bass' => 'x.....x.........', 'scales' => ['minor', 'dorian'], 'lead' => 'epiano', 'leadP' => 0.4, 'bass_t' => '808', 'pad' => 0.22, 'swing' => 0, 'soft' => true],
    ];

    /**
     * Lead voices: partial ratios/weights, decay (per second), vibrato depth.
     *
     * @var array<string, array{partials: list<array{float, float}>, decay: float, vib: float}>
     */
    private const array VOICES = [
        'pluck' => ['partials' => [[1.0, 1.0], [2.0, 0.5], [3.0, 0.25]], 'decay' => 9.0, 'vib' => 0.0],
        'bell' => ['partials' => [[1.0, 1.0], [2.76, 0.4], [5.4, 0.2]], 'decay' => 5.0, 'vib' => 0.0],
        'saw' => ['partials' => [[1.0, 1.0], [2.0, 0.5], [3.0, 0.33], [4.0, 0.25]], 'decay' => 3.0, 'vib' => 0.12],
        'square' => ['partials' => [[1.0, 1.0], [3.0, 0.33], [5.0, 0.2], [7.0, 0.14]], 'decay' => 4.0, 'vib' => 0.0],
        'epiano' => ['partials' => [[1.0, 1.0], [2.0, 0.18], [7.0, 0.06]], 'decay' => 4.5, 'vib' => 0.0],
    ];

    /** @var array<int|string, array{partials: list<array{float, float}>, decay: float, glide: float}> */
    private const array BASSES = [
        'sub' => ['partials' => [[1.0, 1.0], [2.0, 0.18]], 'decay' => 5.0, 'glide' => 0.0],
        '808' => ['partials' => [[1.0, 1.0], [2.0, 0.1]], 'decay' => 2.5, 'glide' => 0.9],
        'saw' => ['partials' => [[1.0, 1.0], [2.0, 0.5], [3.0, 0.33], [4.0, 0.25]], 'decay' => 6.0, 'glide' => 0.0],
        'log' => ['partials' => [[1.0, 1.0], [2.0, 0.35], [3.0, 0.12]], 'decay' => 7.0, 'glide' => 0.5],
    ];

    private const array SECTIONS = [
        'I' => ['pad', 'lead'],
        'A' => ['kick', 'snare', 'hat', 'bass', 'pad'],
        'B' => ['kick', 'snare', 'hat', 'bass', 'lead', 'pad'],
        'K' => ['bass', 'lead', 'pad', 'hat'],
    ];

    /** Song structure, cycled until the target duration is reached. */
    private const array STRUCTURE = ['I', 'A', 'B', 'A', 'B', 'K', 'B', 'B'];

    public function __construct(private readonly string $ffmpeg)
    {
    }

    public static function hasArchetype(string $name): bool
    {
        return isset(self::ARCHETYPES[$name]);
    }

    /**
     * Stage 1: starts the render of the four section files into $dir. Returns the Process (not yet awaited).
     *
     * @param array{archetype: string, seed: int, duration: int} $spec
     */
    public function startSections(array $spec, string $dir): Process
    {
        $plan = $this->plan($spec);
        $dur = $plan['barLen'] * self::BARS_PER_SECTION;
        $graph = [];
        $args = [$this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostats', '-y'];
        $maps = [];
        foreach (self::SECTIONS as $name => $stems) {
            $labels = [];
            foreach ($stems as $stem) {
                $expr = $this->stem($stem, $plan);
                if (null === $expr) {
                    continue;
                }
                $label = $name.'_'.$stem;
                $graph[] = \sprintf("aevalsrc=exprs='%s':s=%d:d=%.4F%s[%s]", $expr['expr'], self::SAMPLE_RATE, $dur, $expr['post'], $label);
                $labels[] = $label;
            }
            if ([] === $labels) {
                $graph[] = \sprintf('anullsrc=r=%d:cl=mono:d=%.4F[%s]', self::SAMPLE_RATE, $dur, $name);
            } else {
                $graph[] = \sprintf('%samix=inputs=%d:normalize=0:duration=longest,alimiter=limit=0.9:level=disabled[%s]', '['.implode('][', $labels).']', \count($labels), $name);
            }
            $maps[$name] = $dir.'/'.$name.'.wav';
        }
        $args[] = '-filter_complex';
        $args[] = implode(';', $graph);
        foreach ($maps as $name => $path) {
            array_push($args, '-map', '['.$name.']', '-c:a', 'pcm_s16le', '-ar', (string) self::SAMPLE_RATE, '-ac', '1', $path);
        }
        $p = new Process($args, null, null, null, 180.0);
        $p->start();

        return $p;
    }

    /**
     * Stage 2: concatenates the sections following the song structure into an MP3 (what a user would upload).
     *
     * @param array{archetype: string, seed: int, duration: int} $spec
     */
    public function startSong(array $spec, string $dir, string $outMp3): Process
    {
        $plan = $this->plan($spec);
        $sectionLen = $plan['barLen'] * self::BARS_PER_SECTION;
        $list = '';
        $total = 0.0;
        $i = 0;
        while ($total < $spec['duration'] + 0.5) {
            $list .= "file '".$dir.'/'.self::STRUCTURE[$i % \count(self::STRUCTURE)].".wav'\n";
            $total += $sectionLen;
            ++$i;
        }
        file_put_contents($dir.'/list.txt', $list);
        $d = $spec['duration'];
        $p = new Process([
            $this->ffmpeg, '-hide_banner', '-loglevel', 'error', '-nostats', '-y',
            '-f', 'concat', '-safe', '0', '-i', $dir.'/list.txt',
            '-t', (string) $d,
            '-af', \sprintf('afade=t=in:d=0.08,afade=t=out:st=%d:d=3', max(1, $d - 3)),
            '-map_metadata', '-1', '-c:a', 'libmp3lame', '-b:a', '128k', '-ar', '44100', '-ac', '2', $outMp3,
        ], null, null, null, 180.0);
        $p->start();

        return $p;
    }

    /**
     * @param array{archetype: string, seed: int, duration: int} $spec
     *
     * @return array<string, mixed>
     */
    private function plan(array $spec): array
    {
        $a = self::ARCHETYPES[$spec['archetype']];
        $rng = new SeedRng($spec['seed']);
        $range = $a['bpm'];
        $bpm = $rng->between($range[0], $range[1]);
        $scales = $a['scales'];
        $scale = $scales[$rng->between(0, \count($scales) - 1)];
        $quality = \in_array($scale, ['minor', 'dorian', 'phrygian', 'pentatonic'], true) ? 'minor' : 'major';
        $progs = self::PROGRESSIONS[$quality];
        $prog = $progs[$rng->between(0, \count($progs) - 1)];
        $step = 15.0 / $bpm;
        /** @var float $leadP */
        $leadP = $a['leadP'];

        return [
            'a' => $a,
            'bpm' => $bpm,
            'step' => $step,
            'barLen' => $step * 16,
            'scale' => $scale,
            'quality' => $quality,
            'scaleCode' => $this->hexCode(self::SCALES[$scale]),
            'prog' => $this->hexCode($prog),
            'root' => $rng->between(33, 40), // bass root, MIDI (A1..E2)
            'leadRoot' => 57 + $rng->between(0, 7) + ('major' === $quality ? 0 : 0),
            'patA' => $this->melody($rng, $leadP),
            'patB' => $this->melody($rng, $leadP),
        ];
    }

    /** @param list<int> $digits */
    private function hexCode(array $digits): string
    {
        $v = 0;
        foreach ($digits as $i => $d) {
            $v += $d * (16 ** $i);
        }

        return (string) $v;
    }

    /** One bar of 16 steps as base-8 digits: 0 = rest, 1..7 = scale degree (a random walk, so it sounds like a line). */
    private function melody(SeedRng $rng, float $density): string
    {
        $deg = [1, 3, 5][$rng->between(0, 2)];
        $v = 0;
        for ($s = 0; $s < 16; ++$s) {
            $on = $rng->float() < $density * ('x' === 'x.x.x.x.x.x.x.x.'[$s] ? 1.4 : 0.8);
            $digit = 0;
            if ($on) {
                $deg = max(1, min(7, $deg + $rng->between(-2, 2)));
                $digit = $deg;
            }
            $v += $digit * (8 ** $s);
        }

        return (string) $v;
    }

    private static function mask(string $pattern): int
    {
        $m = 0;
        foreach (str_split($pattern) as $i => $c) {
            if ('x' === $c) {
                $m |= 1 << $i;
            }
        }

        return $m;
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(\sprintf('%.5F', $v), '0'), '.');
    }

    /**
     * @param array<string, mixed> $plan
     *
     * @return array{expr: string, post: string}|null
     */
    private function stem(string $stem, array $plan): ?array
    {
        /** @var array<string, mixed> $a */
        $a = $plan['a'];
        /** @var float $step */
        $step = $plan['step'];
        $s = self::num($step);
        $len = self::num($step * 16);
        $soft = true === ($a['soft'] ?? false) ? 0.7 : 1.0;
        // slot 0 = time in step, 1 = step in bar, 2 = bar in progression; slot 9 is the noise generator state.
        $pre = "st(0,mod(t,{$s}));st(1,mod(floor(t/{$s}),16));st(2,mod(floor(t/{$len}),4));";
        $fade = "min(1,({$s}-ld(0))*400)";
        $on = static fn (string $pat): string => 'st(3,mod(floor('.self::mask($pat).'/pow(2,ld(1))),2));';
        $drum = static fn (string $key): bool => '' !== (string) ($a[$key] ?? '');

        switch ($stem) {
            case 'kick':
                if (!$drum('kick')) {
                    return null;
                }
                $kd = self::num(3.2 / $step);
                $g = self::num(0.95 * $soft);

                return ['expr' => $pre.$on((string) $a['kick'])."ld(3)*{$g}*sin(2*PI*(52*ld(0)+3.4*(1-exp(-35*ld(0)))))*exp(-{$kd}*ld(0))*{$fade}", 'post' => ',lowpass=f=900'];
            case 'snare':
                if (!$drum('snare')) {
                    return null;
                }
                $sd = self::num(3.6 / $step);
                $g = self::num(0.55 * $soft);

                return ['expr' => $pre.$on((string) $a['snare'])."ld(3)*{$g}*((2*random(9)-1)*0.8*exp(-{$sd}*ld(0))+0.5*sin(2*PI*185*ld(0))*exp(-25*ld(0)))*{$fade}", 'post' => ',highpass=f=180'];
            case 'hat':
                if (!$drum('hat')) {
                    return null;
                }
                $hd = self::num(4.0 / $step);
                $g = self::num(0.30 * $soft);

                return ['expr' => $pre.$on((string) $a['hat'])."ld(3)*{$g}*(0.55+0.45*(1-mod(ld(1),2)))*(2*random(9)-1)*exp(-{$hd}*ld(0))*{$fade}", 'post' => ',highpass=f=6500'];
            case 'bass':
                $b = self::BASSES[(string) $a['bass_t']];
                $root = (int) $plan['root'];
                $glide = self::num($b['glide']);
                $dec = self::num($b['decay']);
                $norm = array_sum(array_column($b['partials'], 1));
                $partials = [];
                foreach ($b['partials'] as [$r, $w]) {
                    $partials[] = self::num($w / $norm).'*sin('.self::num($r).'*ld(6))';
                }
                $g = self::num(0.62 * $soft);

                return [
                    'expr' => $pre.$on((string) $a['bass'])."st(4,mod(floor({$plan['prog']}/pow(16,ld(2))),16));st(5,440*pow(2,({$root}+ld(4)-69)/12));st(6,2*PI*ld(5)*(ld(0)+{$glide}*(1-exp(-30*ld(0)))/30));ld(3)*{$g}*(".implode('+', $partials).")*min(1,ld(0)*300)*exp(-{$dec}*ld(0))*{$fade}",
                    'post' => ',lowpass=f=420',
                ];
            case 'lead':
                $v = self::VOICES[(string) $a['lead']];
                $norm = array_sum(array_column($v['partials'], 1));
                $partials = [];
                foreach ($v['partials'] as [$r, $w]) {
                    $partials[] = self::num($w / $norm).'*sin('.self::num($r).'*ld(6))';
                }
                $dec = self::num($v['decay']);
                $vib = self::num($v['vib']);
                $leadRoot = (int) $plan['leadRoot'];
                $g = self::num(0.34 * $soft);

                return [
                    'expr' => $pre
                        ."st(7,if(lt(mod(floor(t/{$len}),2),1),{$plan['patA']},{$plan['patB']}));"
                        .'st(3,mod(floor(ld(7)/pow(8,ld(1))),8));'
                        ."st(4,mod(floor({$plan['prog']}/pow(16,ld(2))),16));"
                        ."st(5,mod(floor({$plan['scaleCode']}/pow(16,max(ld(3)-1,0))),16));"
                        ."st(6,2*PI*440*pow(2,({$leadRoot}+ld(4)+ld(5)-69)/12)*ld(0)+{$vib}*sin(2*PI*5.5*ld(0)));"
                        ."gt(ld(3),0)*{$g}*(".implode('+', $partials).")*min(1,ld(0)*250)*exp(-{$dec}*ld(0))*{$fade}",
                    'post' => ',lowpass=f=4200,aecho=0.7:0.45:'.(int) round($step * 3000).':0.3',
                ];
            case 'pad':
                $level = (float) $a['pad'];
                $third = 'minor' === $plan['quality'] ? 3 : 4;
                $leadRoot = (int) $plan['leadRoot'] - 12;
                $g = self::num($level);
                $voice = static fn (int $iv, string $det): string => "sin(2*PI*440*pow(2,({$leadRoot}+ld(4)+{$iv}-69)/12){$det}*ld(0))";

                return [
                    'expr' => "st(0,mod(t,{$len}));st(2,mod(floor(t/{$len}),4));st(4,mod(floor({$plan['prog']}/pow(16,ld(2))),16));st(5,sin(PI*ld(0)/{$len}));"
                        ."ld(5)*{$g}*(".$voice(0, '*1').'+'.$voice($third, '*1.004').'+'.$voice(7, '*0.997').'+'.$voice(12, '*1.002').')/4',
                    'post' => ',lowpass=f=2500',
                ];
        }

        return null;
    }
}
