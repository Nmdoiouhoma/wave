<?php

declare(strict_types=1);

namespace App\Command\Seed;

use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\UuidV7;

/**
 * Keeps `activity_event` in step with the demo data (fb-1124). The seed creates accounts, tracks,
 * posts and comments without the processors (no journal) and backdates them, while the admin
 * series read the journal and the « Activité 24 h » card reads the tables: without this the two
 * disagree (series 0 post/0 comment, card 65/129). Same rows as the processors write
 * (actor = subject = author, target), dated like the content, ids v7 of that date (the journal
 * pages by id).
 */
final readonly class SeedJournal
{
    /** type => SQL returning (user_id, target_type, target_id, created_at) of the demo rows */
    private const array SOURCES = [
        'account.registered' => 'SELECT u.id AS user_id, NULL AS target_type, NULL AS target_id, u.created_at FROM app_user u WHERE u.email LIKE :l',
        'content.track_created' => "SELECT a.owner_id, 'track', t.id, t.created_at FROM track t JOIN artist a ON a.id = t.artist_id JOIN app_user u ON u.id = a.owner_id WHERE u.email LIKE :l",
        'content.post_created' => "SELECT p.author_id, 'post', p.id, p.created_at FROM post p JOIN app_user u ON u.id = p.author_id WHERE u.email LIKE :l",
        'content.comment_created' => "SELECT c.author_id, 'comment', c.id, c.created_at FROM comment c JOIN app_user u ON u.id = c.author_id WHERE u.email LIKE :l",
    ];

    private const int BATCH = 500;

    public function __construct(private Connection $db)
    {
    }

    /**
     * Before the demo accounts are deleted by SQL: drops their journal like an account deletion
     * does (DATA_MODEL §13b, admin.* kept), plus content events already orphaned by older seeds
     * (actor NULL: a content event always has an author).
     */
    public function forgetDemo(string $emailLike): void
    {
        $this->db->executeStatement(
            "DELETE FROM activity_event WHERE category <> 'admin' AND (
                 actor_id IN (SELECT id FROM app_user WHERE email LIKE :l)
              OR subject_user_id IN (SELECT id FROM app_user WHERE email LIKE :l)
              OR (category = 'content' AND actor_id IS NULL))",
            ['l' => $emailLike],
        );
    }

    /** After the timestamps are final: one event per demo account, track, post and comment. */
    public function backfill(string $emailLike): int
    {
        $n = 0;
        foreach (self::SOURCES as $type => $sql) {
            $category = explode('.', $type)[0];
            $rows = $this->db->fetchAllNumeric($sql, ['l' => $emailLike]);
            foreach (array_chunk($rows, self::BATCH) as $chunk) {
                $values = [];
                $params = [];
                foreach ($chunk as $i => [$user, $targetType, $targetId, $at]) {
                    $date = new \DateTimeImmutable((string) $at);
                    $values[] = "(:id{$i}, :cat, :type, :u{$i}, :u{$i}, :tt{$i}, :t{$i}, '{}'::jsonb, :at{$i})";
                    $params += [
                        "id{$i}" => UuidV7::generate($date),
                        "u{$i}" => $user,
                        "tt{$i}" => $targetType,
                        "t{$i}" => $targetId,
                        "at{$i}" => $date->format('Y-m-d H:i:s.uP'),
                    ];
                }
                $n += $this->db->executeStatement(
                    'INSERT INTO activity_event (id, category, type, actor_id, subject_user_id, target_type, target_id, metadata, created_at) VALUES '.implode(', ', $values),
                    $params + ['cat' => $category, 'type' => $type],
                );
            }
        }

        return $n;
    }
}
