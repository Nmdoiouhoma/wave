<?php

declare(strict_types=1);

namespace App\Command\Seed;

/** Dev seed only: the fixed scenario (10 accounts, 12 artists, 43 tracks, audiences, posts). Nothing random here. */
final class Catalog
{
    public const string PASSWORD = 'WaveDemo2026!';

    /**
     * key => [display name, bio, has avatar].
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function users(): array
    {
        return [
            'amina' => ['Amina Benali', 'Rappeuse du 93, plume avant tout. Je poste mes sons avant tout le monde.', true],
            'lucas' => ['Lucas Martin', 'Producteur house et techno à Lyon. Les kicks, c\'est ma vie.', true],
            'yasmine' => ['Yasmine Traoré', 'Afrobeats, amapiano et bonne humeur. Toujours en quête de la prochaine piste de danse.', true],
            'karim' => ['Karim Haddad', 'Drill et trap, Marseille. Je fais aussi du rap plus posé.', false],
            'chloe' => ['Chloé Lefèvre', 'Chanteuse et auteure, entre pop et chanson. Je cherche mes mots.', true],
            'mamadou' => ['Mamadou Diallo', 'Coupé-décalé et afro, depuis Abidjan jusqu\'à Paris.', true],
            'sofia' => ['Sofia Rossi', 'Pianiste de jazz le soir, prof de solfège le jour.', true],
            'theo' => ['Théo Garnier', 'Guitare, amplis et réverb. Un pied dans le rock, l\'autre dans le techno.', false],
            'ines' => ['Inès Moreau', 'R&B et soul, voix de chambre. Je découvre plus que je ne publie.', true],
            'hugo' => ['Hugo Lambert', 'Je débute. Un clavier, un ordinateur et beaucoup de patience.', false],
        ];
    }

    /**
     * key => [owner, name, bio, has avatar].
     *
     * @return array<string, array{string, string, string, bool}>
     */
    public static function artists(): array
    {
        return [
            'amina_b' => ['amina', 'Amina B.', 'Rap français.', true],
            'mina_beats' => ['amina', 'Mina Beats', 'Instrumentaux lo-fi et beats.', false],
            'lucas_vale' => ['lucas', 'Lucas Vale', 'House et techno.', true],
            'yaz' => ['yasmine', 'Yaz', 'Afrobeats et amapiano.', true],
            'krim' => ['karim', 'K-Rim', 'Drill, trap et rap.', false],
            'chloe_lune' => ['chloe', 'Chloé Lune', 'Pop, indie et chanson.', false],
            'mamadou_d' => ['mamadou', 'Mamadou D', 'Coupé-décalé et afro.', true],
            'sofia_rossi' => ['sofia', 'Sofia Rossi', 'Jazz et soul.', true],
            'theo_g' => ['theo', 'Théo G', 'Rock et indie.', false],
            'neon_garnier' => ['theo', 'Neon Garnier', 'Techno et ambient.', false],
            'ines_m' => ['ines', 'Inès M', 'R&B.', false],
            'hugo_l' => ['hugo', 'Hugo L', 'Premiers essais.', false],
        ];
    }

    /**
     * Number (1-based, used by posts and playlists) => [artist, title, genre, archetype, seconds, audience, allowClips, cover].
     *
     * @return array<int, array{string, string, string, string, int, string, bool, bool}>
     */
    public static function tracks(): array
    {
        return [
            1 => ['amina_b', 'Minuit sur le périph', 'rap-fr', 'boombap', 145, 'public', true, true],
            2 => ['amina_b', 'Les mêmes rues', 'rap-fr', 'boombap', 117, 'public', true, false],
            3 => ['amina_b', 'Café noir', 'rap-fr', 'trap', 128, 'followers', true, false],
            4 => ['amina_b', 'Lettre à Maman', 'rap-fr', 'rnb', 140, 'close_friends', true, false],
            5 => ['amina_b', 'Brouillon', 'rap-fr', 'boombap', 96, 'private', true, false],
            6 => ['mina_beats', 'Pluie sur Belleville', 'lofi', 'lofi', 135, 'public', true, true],
            7 => ['mina_beats', 'Sieste d\'août', 'lofi', 'lofi', 130, 'public', true, false],
            8 => ['mina_beats', 'Cassette 94', 'beats', 'boombap', 75, 'public', true, false],
            9 => ['lucas_vale', 'Neon Lines', 'house', 'fourfloor', 187, 'public', true, true],
            10 => ['lucas_vale', 'Gare du Nord, 4h', 'electronic', 'techno', 145, 'public', false, false],
            11 => ['lucas_vale', 'Warehouse', 'techno', 'techno', 240, 'followers', true, false],
            12 => ['lucas_vale', 'Pulse (démo)', 'electronic', 'fourfloor', 110, 'private', true, false],
            13 => ['lucas_vale', 'Rooftop Sunrise', 'house', 'fourfloor', 140, 'close_friends', true, false],
            14 => ['yaz', 'Soleil de Lagos', 'afrobeats', 'afrobeats', 136, 'public', true, true],
            15 => ['yaz', 'Log drum', 'amapiano', 'amapiano', 135, 'public', true, false],
            16 => ['yaz', 'Sur la piste', 'afro', 'afrobeats', 120, 'public', true, false],
            17 => ['krim', 'Zone 9', 'drill', 'drill', 118, 'public', true, true],
            18 => ['krim', 'Tarmac', 'trap', 'trap', 134, 'public', true, false],
            19 => ['krim', 'Cinq heures du mat', 'rap', 'boombap', 135, 'public', true, false],
            20 => ['krim', 'Sous scellés', 'rap', 'trap', 100, 'followers', true, false],
            21 => ['chloe_lune', 'Fenêtre ouverte', 'pop', 'pop', 120, 'public', true, true],
            22 => ['chloe_lune', 'Marées', 'indie', 'ballad', 155, 'public', true, false],
            23 => ['chloe_lune', 'Le quai des brumes', 'chanson', 'ballad', 130, 'public', true, false],
            24 => ['chloe_lune', 'Papier froissé', 'pop', 'pop', 120, 'followers', true, false],
            25 => ['mamadou_d', 'Abidjan Nights', 'coupe-decale', 'coupe', 130, 'public', true, true],
            26 => ['mamadou_d', 'Djandjou', 'coupe-decale', 'coupe', 135, 'public', true, false],
            27 => ['mamadou_d', 'Kora et vinyle', 'afro', 'afrobeats', 125, 'public', true, false],
            28 => ['mamadou_d', 'Ma sœur', 'afro', 'ballad', 145, 'close_friends', true, false],
            29 => ['sofia_rossi', 'Blue Hour', 'jazz', 'jazz', 235, 'public', true, true],
            30 => ['sofia_rossi', 'Café Lungo', 'jazz', 'jazz', 140, 'public', true, false],
            31 => ['sofia_rossi', 'Velours', 'soul', 'rnb', 130, 'public', true, false],
            32 => ['theo_g', 'Soixante-dix', 'rock', 'rock', 135, 'public', true, true],
            33 => ['theo_g', 'Route 7', 'rock', 'rock', 120, 'public', true, false],
            34 => ['theo_g', 'Cendres', 'indie', 'ballad', 120, 'followers', true, false],
            35 => ['neon_garnier', 'Drone Garden', 'ambient', 'ambient', 240, 'public', true, false],
            36 => ['neon_garnier', 'Circuit Court', 'techno', 'techno', 135, 'public', true, false],
            37 => ['neon_garnier', 'Aurore boréale', 'ambient', 'ambient', 140, 'public', true, true],
            38 => ['ines_m', 'Minuit passé', 'rnb', 'rnb', 125, 'public', true, true],
            39 => ['ines_m', 'Velvet', 'rnb', 'rnb', 130, 'public', true, false],
            40 => ['ines_m', 'Slow Burn', 'rnb', 'rnb', 140, 'followers', true, false],
            41 => ['hugo_l', 'Premier essai', 'ambient', 'ambient', 65, 'public', true, false],
            42 => ['hugo_l', 'Vide-grenier', 'lofi', 'lofi', 100, 'public', true, false],
            43 => ['lucas_vale', 'Breakbeat 174', 'drum-and-bass', 'dnb', 120, 'public', true, false],
        ];
    }

    /**
     * follower => followed (asymmetric on purpose: amina followed by 8 of 9, ines does not follow her, nobody follows hugo).
     *
     * @return array<string, list<string>>
     */
    public static function follows(): array
    {
        return [
            'amina' => ['lucas', 'yasmine', 'karim', 'mamadou', 'chloe'],
            'lucas' => ['amina', 'yasmine', 'chloe', 'theo', 'sofia'],
            'yasmine' => ['amina', 'mamadou', 'ines', 'lucas'],
            'karim' => ['amina', 'mamadou', 'lucas', 'theo'],
            'chloe' => ['amina', 'lucas', 'sofia', 'ines'],
            'mamadou' => ['amina', 'yasmine', 'karim', 'sofia'],
            'sofia' => ['amina', 'chloe', 'lucas'],
            'theo' => ['amina', 'lucas', 'karim'],
            'ines' => ['lucas', 'sofia', 'theo', 'yasmine'],
            'hugo' => ['amina', 'sofia'],
        ];
    }

    /**
     * Close-friends lists (owner => friends). A friend must already follow the owner (fk_close_friend_follow).
     *
     * @return array<string, list<string>>
     */
    public static function closeFriends(): array
    {
        return [
            'amina' => ['lucas', 'yasmine'],
            'lucas' => ['amina', 'chloe'],
            'mamadou' => ['karim', 'yasmine'],
        ];
    }

    /**
     * Posts: [author, track number, clip start s, clip seconds, caption, visual (none|image|video), hours ago].
     *
     * @return list<array{string, int, int, int, string, string, int}>
     */
    public static function posts(): array
    {
        return [
            ['amina', 1, 30, 20, 'Nouveau son, dites-moi ce que vous en pensez #rapfr #nouveaute', 'image', 5],
            ['amina', 3, 20, 25, 'Pour mes abonnés seulement #cafenoir', 'video', 20],
            ['amina', 4, 40, 20, 'Un morceau pour les proches #famille', 'image', 30],
            ['amina', 5, 10, 15, 'Brouillon perso, ne pas diffuser', 'none', 50],
            ['lucas', 9, 25, 30, 'Neon Lines, extrait du live #house #electro', 'video', 8],
            ['lucas', 13, 60, 20, 'Lever de soleil sur les toits #sunrise', 'image', 40],
            ['yasmine', 14, 50, 30, 'Soleil de Lagos, version dansante #afrobeats #summer', 'video', 12],
            ['karim', 17, 20, 20, 'Zone 9 #drill', 'image', 3],
            ['chloe', 21, 30, 25, 'Fenêtre ouverte, premiers vers #pop #indie', 'none', 70],
            ['mamadou', 25, 40, 30, 'Abidjan Nights #coupedecale', 'video', 15],
            ['sofia', 29, 90, 40, 'Blue Hour, session de nuit #jazz', 'image', 26],
            ['yasmine', 6, 30, 20, 'Parfait pour réviser #lofi', 'none', 6],
            ['theo', 36, 60, 20, 'Circuit Court #techno', 'video', 33],
            ['ines', 9, 100, 20, 'J\'écoute ça en boucle #house', 'image', 2],
            ['hugo', 6, 20, 20, 'Première fois que j\'ose poster #lofi', 'none', 1],
            ['mamadou', 28, 30, 20, 'Pour ma sœur #famille', 'image', 60],
        ];
    }

    /**
     * Playlists: [owner, name, description, public, track numbers].
     *
     * @return list<array{string, string, string, bool, list<int>}>
     */
    public static function playlists(): array
    {
        return [
            ['amina', 'Trajet du matin', 'Ce que j\'écoute dans le RER.', true, [6, 9, 14, 21, 29]],
            ['amina', 'À retravailler', 'Mes brouillons.', false, [1, 2, 5]],
            ['lucas', 'Warm-up set', 'Pour chauffer la salle.', true, [10, 11, 36, 9, 35]],
            ['yasmine', 'Soirée afro', 'Pour danser jusqu\'au matin.', true, [14, 15, 16, 25, 26, 27]],
            ['karim', 'Salle de sport', 'Du lourd.', true, [17, 18, 19, 20]],
            ['sofia', 'Dîner jazz', 'Fond sonore pour un dîner.', true, [29, 30, 31, 22]],
            ['mamadou', 'Dimanche calme', 'Pour souffler.', true, [6, 7, 22, 31, 37]],
            ['ines', 'Late night', 'Après minuit.', true, [38, 39, 31, 22]],
        ];
    }

    /** @return list<string> */
    public static function comments(): array
    {
        return [
            'Grosse vibe, merci pour le partage !',
            'Le refrain est resté dans ma tête toute la journée.',
            'La prod est incroyable.',
            'Tu as un nouvel abonné.',
            'On dirait un morceau que j\'ai déjà entendu quelque part, mais en mieux.',
            'Ce passage à 0:30, quel moment.',
            'Bravo, continue comme ça.',
            'Le mix est super propre.',
            'À quand le prochain ?',
            'Je mets ça dans ma playlist.',
        ];
    }
}
