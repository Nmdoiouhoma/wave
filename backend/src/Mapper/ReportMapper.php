<?php

declare(strict_types=1);

namespace App\Mapper;

use App\ApiResource\ReportTargetPreview;
use App\ApiResource\ReportTargetView;
use App\ApiResource\ReportView;
use App\Entity\Artist;
use App\Entity\Comment;
use App\Entity\Playlist;
use App\Entity\Post;
use App\Entity\Report;
use App\Entity\Track;
use App\Entity\User;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Enum\VisualType;
use App\Moderation\ReportTargetResolver;
use App\Repository\ReportRepository;
use App\Storage\ObjectStorage;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Moderation views (DATA_MODEL §13). Targets of a page are loaded in one query per type, with
 * the relations the preview needs fetch-joined.
 *
 * @phpstan-import-type TargetRow from ReportRepository
 */
final readonly class ReportMapper
{
    public const int LABEL_MAX = 140;

    /** @var array<string, list<string>> joins fetched per target type (alias x) */
    private const array JOINS = [
        'track' => ['owner'],
        'post' => ['author', 'track'],
        'comment' => ['author'],
        'playlist' => ['owner'],
        'artist' => ['owner'],
        'user' => [],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ReportRepository $reports,
        private UserMapper $users,
        private ObjectStorage $storage,
    ) {
    }

    public function toView(Report $report): ReportView
    {
        $handler = $report->getHandledBy();

        return new ReportView(
            $report->getId()->toRfc4122(),
            $this->users->toSummary($report->getReporter()),
            $report->getTargetType()->value,
            $report->getTargetId()->toRfc4122(),
            $report->getReason()->value,
            $report->getDetails(),
            $report->getStatus()->value,
            null === $handler ? null : $this->users->toSummary($handler),
            DateFormat::nullable($report->getHandledAt()),
            $report->getDecisionNote(),
            DateFormat::format($report->getCreatedAt()),
        );
    }

    /**
     * @param list<TargetRow> $rows
     *
     * @return list<ReportTargetView>
     */
    public function toTargetViews(array $rows, ReportStatus $status): array
    {
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['target_type']][] = $row['target_id'];
        }
        $targets = [];
        foreach ($byType as $type => $ids) {
            $targets += $this->load($type, $ids);
        }
        $reasons = $this->reports->reasonCounts($status, array_values(array_unique(array_column($rows, 'target_id'))));

        return array_map(function (array $row) use ($targets, $reasons): ReportTargetView {
            $key = $row['target_type'].':'.$row['target_id'];

            return $this->targetView(ReportTargetType::from($row['target_type']), $row['target_id'], $targets[$key] ?? null, $row['n'], $reasons[$key] ?? [], $row['first_at'], $row['last_at']);
        }, $rows);
    }

    /**
     * @param array<string, int> $reasons
     */
    public function targetView(
        ReportTargetType $type,
        string $id,
        Track|Post|Comment|Playlist|Artist|User|null $target,
        int $count,
        array $reasons,
        \DateTimeImmutable $first,
        \DateTimeImmutable $last,
    ): ReportTargetView {
        $moderation = null;
        $suspended = null;
        if ($target instanceof User) {
            $suspended = $target->isSuspended();
        } elseif (null !== $target) {
            $moderation = $target->getModeration()->value;
        }

        return new ReportTargetView(
            $type->value,
            $id,
            null === $target ? null : $this->preview($target),
            $moderation,
            $suspended,
            $count,
            (object) $reasons,
            DateFormat::format($first),
            DateFormat::format($last),
        );
    }

    private function preview(Track|Post|Comment|Playlist|Artist|User $target): ReportTargetPreview
    {
        [$label, $imageKey, $owner] = match (true) {
            $target instanceof Track => [$target->getTitle(), $target->getCoverKey(), $target->getOwner()],
            $target instanceof Post => [$target->getCaption() ?? $target->getTrack()->getTitle(), self::postImageKey($target), $target->getAuthor()],
            $target instanceof Comment => [$target->getBody(), null, $target->getAuthor()],
            $target instanceof Playlist => [$target->getName(), null, $target->getOwner()],
            $target instanceof Artist => [$target->getName(), $target->getAvatarKey(), $target->getOwner()],
            $target instanceof User => [$target->getUsername(), $target->getAvatarKey(), $target],
        };

        return new ReportTargetPreview(
            mb_substr($label, 0, self::LABEL_MAX),
            null === $imageKey ? null : $this->storage->publicUrl($imageKey),
            $this->users->toSummary($owner),
        );
    }

    /** Image visual, or the poster of a video visual. */
    private static function postImageKey(Post $post): ?string
    {
        return VisualType::Image === $post->getVisualType() ? $post->getVisualKey() : $post->getVisualPosterKey();
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, Track|Post|Comment|Playlist|Artist|User> "type:id" => target
     */
    private function load(string $type, array $ids): array
    {
        $qb = $this->em->createQueryBuilder()->select('x')->from(ReportTargetResolver::CLASSES[$type], 'x')
            ->andWhere('x.id IN (:ids)')->setParameter('ids', $ids);
        foreach (self::JOINS[$type] as $i => $relation) {
            $qb->addSelect('j'.$i)->leftJoin('x.'.$relation, 'j'.$i);
        }
        $out = [];
        /** @var Track|Post|Comment|Playlist|Artist|User $target */
        foreach ($qb->getQuery()->getResult() as $target) {
            $out[$type.':'.$target->getId()->toRfc4122()] = $target;
        }

        return $out;
    }
}
