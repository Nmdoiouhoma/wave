<?php

declare(strict_types=1);

namespace App\Command\Seed;

/**
 * Dev seed only (W-42): the generated part of the demo data, from fixed seeds (same output on every run, no
 * clock, no database). Adds 22 accounts, 28 artists, 107 tracks and 284 posts to the hand-written scenario
 * of {@see Catalog}, to reach 32 accounts, 40 artists, 150 tracks and 300 posts.
 */
final class Generator
{
    public const int HAND_MAX_SECONDS = 24;
    public const int TRACK_MIN_SECONDS = 20;
    public const int TRACK_MAX_SECONDS = 24;
    public const int GENERATED_USERS = 22;
    public const int GENERATED_ARTISTS = 28;
    public const int GENERATED_TRACKS = 107;
    public const int GENERATED_POSTS = 284;

    /** Account name => last name. Keys are the ASCII username and the e-mail local part. */
    private const array PEOPLE = [
        'nadia' => 'Kessler', 'omar' => 'Belkacem', 'jade' => 'Fontaine', 'malik' => 'Cissé', 'lea' => 'Marchand',
        'samir' => 'Ouali', 'camille' => 'Roux', 'bilal' => 'Mansouri', 'manon' => 'Dubois', 'tidiane' => 'Konaté',
        'eva' => 'Girard', 'ibrahim' => 'Sow', 'lina' => 'Bouzid', 'noah' => 'Perrin', 'salome' => 'Lemaire',
        'rayan' => 'Chaabane', 'anais' => 'Colin', 'kofi' => 'Mensah', 'maya' => 'Fabre', 'adam' => 'Rahmani',
        'louna' => 'Vidal', 'sacha' => 'Morel',
    ];

    private const array BIOS = [
        'Je poste ce que je fais le soir, après le travail.',
        'Sons faits maison, mixés sur un casque à 40 euros.',
        'Toujours en train de chercher le bon refrain.',
        'Producteur de chambre, fan de samples imaginaires.',
        'Un micro, une idée, trois versions.',
        'Pas de règles, juste des morceaux.',
        'Je découvre autant que je publie.',
        'Basse en avant, toujours.',
        'Chanter sous la douche a fini par devenir un projet.',
        'Dites-moi ce qui vous plaît, je garde.',
    ];

    /** Style: [archetype, genres] (ids of the `genre` table). */
    private const array STYLES = [
        ['boombap', ['rap-fr', 'rap', 'hip-hop', 'beats']],
        ['trap', ['trap', 'rap', 'hip-hop']],
        ['drill', ['drill', 'rap']],
        ['fourfloor', ['house', 'electronic', 'edm']],
        ['techno', ['techno', 'electronic']],
        ['dnb', ['drum-and-bass', 'electronic']],
        ['afrobeats', ['afrobeats', 'afro', 'world']],
        ['amapiano', ['amapiano', 'afro']],
        ['dembow', ['reggaeton', 'dancehall', 'latin']],
        ['lofi', ['lofi', 'beats', 'instrumental']],
        ['ballad', ['chanson', 'folk', 'indie', 'variete-fr']],
        ['ambient', ['ambient', 'soundtrack', 'classical', 'instrumental']],
        ['rock', ['rock', 'indie', 'metal', 'punk', 'blues']],
        ['jazz', ['jazz', 'blues', 'funk', 'soul']],
        ['coupe', ['coupe-decale', 'zouk', 'kompa']],
        ['pop', ['pop', 'k-pop', 'variete-fr']],
        ['rnb', ['rnb', 'soul', 'gospel']],
    ];

    private const array STAGE_NAMES = [
        'Nadia K', 'Omar Flow', 'Jade Fontaine', 'Malik Sound', 'Léa M', 'Samir O', 'Camille R', 'Bilal Beats',
        'Manon D', 'Tidiane K', 'Eva G', 'Ibra Sow', 'Lina B', 'Noah P', 'Salomé', 'Rayan Ch', 'Anaïs C',
        'Kofi M', 'Maya F', 'Adam R', 'Louna V', 'Sacha M', 'Orbite 13', 'Les Voisins', 'Velours Noir',
        'Moteur Froid', 'Petite Marée', 'Dix-sept Étages',
    ];

    private const array ADJECTIVES = [
        'dernier', 'lent', 'bleu', 'froid', 'doux', 'sauvage', 'perdu', 'brûlant', 'secret', 'lointain', 'fragile',
        'électrique', 'long', 'blanc', 'vide', 'nouveau', 'ancien', 'rapide', 'tendre', 'noir',
    ];

    private const array NOUNS = [
        'matin', 'train', 'orage', 'été', 'signal', 'quartier', 'souvenir', 'rideau', 'tunnel', 'balcon', 'phare',
        'trottoir', 'refrain', 'mirage', 'terminus', 'couloir', 'sillage', 'néon', 'vertige', 'horizon', 'carnet',
        'ruban', 'décor', 'écho', 'brouillard',
    ];

    private const array CAPTION_STARTS = [
        'Extrait de mon dernier morceau', 'Dites-moi ce que vous en pensez', 'Premier jet, soyez gentils',
        'Ce passage me rend fou', 'Studio du dimanche', 'Version brute, sans retouche', 'À écouter fort',
        'Un refrain qui reste', 'Nouveau son dispo', 'Ce son tourne en boucle chez moi', 'Mon coup de cœur du jour',
        'Merci pour vos retours', 'Petit teaser', 'Écoutez jusqu\'au bout',
    ];

    private const array TAGS = [
        'nouveaute', 'studio', 'beat', 'live', 'cover', 'freestyle', 'weekend', 'nuit', 'summer', 'vibes', 'son',
        'maison', 'demo', 'extrait', 'prod', 'refrain', 'mix', 'instru',
    ];

    /** @return array<string, array{string, string, bool}> */
    public static function users(): array
    {
        $rng = new SeedRng(4201);
        $out = [];
        foreach (self::PEOPLE as $key => $last) {
            $out[$key] = [ucfirst($key).' '.$last, self::BIOS[$rng->between(0, \count(self::BIOS) - 1)], $rng->float() < 0.6];
        }

        return $out;
    }

    /** @return array<string, array{string, string, string, bool}> */
    public static function artists(): array
    {
        $rng = new SeedRng(4202);
        $owners = array_keys(self::PEOPLE);
        $out = [];
        foreach (self::STAGE_NAMES as $i => $name) {
            // 22 owners, 28 artists: the first 6 owners have two artists.
            $owner = $owners[$i < \count($owners) ? $i : $i - \count($owners)];
            $out['g_'.$i] = [$owner, $name, self::STYLE_LABELS[$i % \count(self::STYLES)], $rng->float() < 0.55];
        }

        return $out;
    }

    private const array STYLE_LABELS = [
        'Rap et beats.', 'Trap, rap.', 'Drill.', 'House et électro.', 'Techno.', 'Drum and bass.', 'Afrobeats.',
        'Amapiano.', 'Reggaeton et dancehall.', 'Lo-fi et instrumentaux.', 'Chanson et folk.', 'Ambient.', 'Rock.',
        'Jazz et soul.', 'Coupé-décalé.', 'Pop.', 'R&B.',
    ];

    /**
     * @param array<int, array{string, string, string, string, int, string, bool, bool}> $hand
     *
     * @return array<int, array{string, string, string, string, int, string, bool, bool}>
     */
    public static function tracks(array $hand): array
    {
        $rng = new SeedRng(4203);
        $first = \count($hand) + 1;
        $out = [];
        $titles = [];
        for ($i = 0; $i < self::GENERATED_TRACKS; ++$i) {
            // Every artist gets at least 3 tracks; the rest goes to the first artists (a few prolific ones).
            $a = $i < 3 * self::GENERATED_ARTISTS ? $i % self::GENERATED_ARTISTS : $rng->between(0, 11);
            [$archetype, $genres] = self::STYLES[$a % \count(self::STYLES)];
            $title = '';
            do {
                $title = ucfirst(self::NOUNS[$rng->between(0, \count(self::NOUNS) - 1)]).' '.self::ADJECTIVES[$rng->between(0, \count(self::ADJECTIVES) - 1)];
            } while (isset($titles[$title]));
            $titles[$title] = true;
            $r = $rng->float();
            $audience = match (true) {
                $r < 0.80 => 'public',
                $r < 0.88 => 'followers',
                $r < 0.93 => 'close_friends',
                default => 'private',
            };
            $out[$first + $i] = [
                'g_'.$a, $title, $genres[$rng->between(0, \count($genres) - 1)], $archetype,
                $rng->between(self::TRACK_MIN_SECONDS, self::TRACK_MAX_SECONDS), $audience, $rng->float() < 0.88, $rng->float() < 0.4,
            ];
        }

        return $out;
    }

    /**
     * @param array<int, array{string, string, string, string, int, string, bool, bool}> $tracks all tracks
     * @param array<int, array{string, string, string, string, int, string, bool, bool}> $hand
     *
     * @return list<array{string, int, int, int, string, string, int}>
     */
    public static function posts(array $tracks, array $hand): array
    {
        $rng = new SeedRng(4204);
        $owner = static fn (int $n): string => Catalog::artists()[$tracks[$n][0]][0];
        $generated = array_keys(array_diff_key($tracks, $hand));
        // Public tracks that others may reuse for a clip (ADR: public and allowClips).
        $reusable = array_values(array_filter(array_keys($tracks), static fn (int $n): bool => 'public' === $tracks[$n][5] && $tracks[$n][6]));
        $people = array_keys(self::PEOPLE);
        $out = [];
        for ($i = 0; $i < self::GENERATED_POSTS; ++$i) {
            $reuse = $rng->float() < 0.22;
            if ($reuse) {
                $n = $reusable[$rng->between(0, \count($reusable) - 1)];
                $author = $people[$rng->between(0, \count($people) - 1)];
                if ($author === $owner($n)) {
                    $author = $people[($rng->between(0, \count($people) - 1) + 1) % \count($people)];
                    if ($author === $owner($n)) {
                        $reuse = false;
                    }
                }
            }
            if (!$reuse) {
                // Own track (generated tracks only, so the 43 hand tracks keep their scenario posts).
                $n = $generated[$rng->between(0, \count($generated) - 1)];
                $author = $owner($n);
            }
            $secs = $rng->between(15, 18);
            $start = $rng->between(0, max(0, $tracks[$n][4] - $secs - 2));
            $tag1 = self::TAGS[$rng->between(0, \count(self::TAGS) - 1)];
            $tag2 = $tracks[$n][2];
            $caption = self::CAPTION_STARTS[$rng->between(0, \count(self::CAPTION_STARTS) - 1)].' #'.$tag1.($rng->float() < 0.6 ? ' #'.str_replace('-', '', $tag2) : '');
            $v = $rng->float();
            $visual = $v < 0.35 ? 'image' : ($v < 0.65 ? 'video' : 'none');
            // Mostly recent (last 3 days), a long tail up to 14 days.
            $hours = $rng->float() < 0.6 ? $rng->between(1, 72) : $rng->between(73, 336);
            $out[] = [$author, $n, $start, $secs, $caption, $visual, $hours];
        }

        return $out;
    }
}
