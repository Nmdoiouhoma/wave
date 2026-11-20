<?php

declare(strict_types=1);

namespace App\Repository;

use App\Post\HashtagExtractor;
use App\Security\TrackAudience;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Read side of `hashtag` (W-41), plain SQL like its write side. A hashtag is only shown to a
 * viewer who can play at least one of its posts: its name must not reveal restricted posts.
 */
final readonly class HashtagRepository
{
    public function __construct(private Connection $db)
    {
    }

    /**
     * The hashtag named $name (any case, NFKC-normalised here) when $viewerId can play one of its
     * posts; null for an invalid or unknown name and for a hashtag with nothing playable.
     *
     * @return array{id: string, name: string, postCount: int}|null
     */
    public function findVisibleByName(mixed $name, Uuid $viewerId): ?array
    {
        if (!\is_string($name) || !mb_check_encoding($name, 'UTF-8')) {
            return null;
        }
        $name = \Normalizer::normalize(mb_strtolower($name, 'UTF-8'), \Normalizer::FORM_KC);
        if (false === $name || 1 !== preg_match(HashtagExtractor::NAME_PATTERN, $name)) {
            return null;
        }
        $row = $this->db->fetchAssociative(
            'SELECT h.id, h.name, h.post_count FROM hashtag h
             WHERE h.name = :name AND EXISTS (
                 SELECT 1 FROM post_hashtag ph
                 JOIN post p ON p.id = ph.post_id
                 JOIN track t ON t.id = p.track_id
                 JOIN app_user u ON u.id = p.author_id
                 WHERE ph.hashtag_id = h.id AND '.TrackAudience::postPlayableSql('p', 't', 'u').'
             )',
            ['name' => $name, 'v' => $viewerId->toRfc4122()],
        );

        return false === $row ? null : ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'postCount' => (int) $row['post_count']];
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<array{id: string, name: string, postCount: int}> in the order of $ids (missing ones skipped)
     */
    public function findByIdsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, post_count FROM hashtag WHERE id IN (:ids)',
            ['ids' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids)],
            ['ids' => ArrayParameterType::STRING],
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(string) $row['id']] = ['id' => (string) $row['id'], 'name' => (string) $row['name'], 'postCount' => (int) $row['post_count']];
        }
        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id->toRfc4122()])) {
                $ordered[] = $byId[$id->toRfc4122()];
            }
        }

        return $ordered;
    }
}
