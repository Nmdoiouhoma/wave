<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\Activity\AdminQuery;
use App\ApiResource\AnnouncementView;
use App\Dto\Input\CreateAnnouncementInput;
use App\Dto\Input\UpdateAnnouncementInput;
use App\Entity\Announcement;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Http\JsonInputDecoder;
use App\Mapper\JournalMapper;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * Admin announcements (DATA_MODEL §17), ROLE_ADMIN: POST 201, PATCH 200 (bumps `updatedAt`),
 * DELETE 204. Order: moderator → 403 (before any lookup) → body → 422 → unknown id → 404.
 * Dates are RFC 3339 (`2026-11-13T10:00:00Z` / `+01:00`), anything else → 422; `endsAt` must
 * be after `startsAt` (on the merged values for PATCH). Each write is journaled
 * `admin.announcement_*` in the same transaction.
 *
 * @implements ProcessorInterface<mixed, AnnouncementView|null>
 */
final readonly class AnnouncementProcessor implements ProcessorInterface
{
    private const string RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/i';

    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private EntityManagerInterface $em,
        private ActivityJournal $journal,
        private JournalMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ?AnnouncementView
    {
        $admin = AdminQuery::requireAdmin($this->currentUser->require());
        /** @var Request $request */
        $request = $context['request'];

        if ($operation instanceof Post) {
            return $this->create($admin, $request);
        }
        if ($operation instanceof DeleteOperationInterface) {
            $announcement = $this->find($uriVariables);
            $id = $announcement->getId();
            $this->em->wrapInTransaction(function () use ($announcement, $admin, $id): void {
                $this->em->remove($announcement);
                $this->em->flush();
                $this->journal->record(ActivityType::AnnouncementDeleted, $admin, null, 'announcement', $id);
            });

            return null;
        }

        return $this->update($admin, $request, $uriVariables);
    }

    private function create(User $admin, Request $request): AnnouncementView
    {
        $input = $this->decoder->decode($request, CreateAnnouncementInput::class);
        $startsAt = self::date($input->startsAt, 'startsAt') ?? $this->clock->now();
        $endsAt = self::date($input->endsAt, 'endsAt');
        self::checkRange($startsAt, $endsAt);
        \assert(null !== $input->level);
        $announcement = new Announcement(trim((string) $input->title), trim((string) $input->body), $input->level, $startsAt, $endsAt, $admin);

        $this->em->wrapInTransaction(function () use ($announcement, $admin): void {
            $this->em->persist($announcement);
            $this->em->flush();
            $this->journal->record(ActivityType::AnnouncementCreated, $admin, null, 'announcement', $announcement->getId(), ['level' => $announcement->getLevel()->value]);
        });

        return $this->mapper->announcement($announcement);
    }

    /** @param array<string, mixed> $uriVariables */
    private function update(User $admin, Request $request, array $uriVariables): AnnouncementView
    {
        $input = $this->decoder->decode($request, UpdateAnnouncementInput::class);
        $input->rejectExplicitNull('title', 'body', 'level', 'startsAt');
        if ([] === array_filter(['title', 'body', 'level', 'startsAt', 'endsAt'], $input->has(...))) {
            throw ApiProblemException::validation('', 'At least one field is required.');
        }
        $startsAt = self::date($input->startsAt, 'startsAt');
        $setEndsAt = $input->has('endsAt');
        $endsAt = self::date($input->endsAt, 'endsAt');

        $announcement = $this->find($uriVariables);
        self::checkRange($startsAt ?? $announcement->getStartsAt(), $setEndsAt ? $endsAt : $announcement->getEndsAt());

        $this->em->wrapInTransaction(function () use ($announcement, $admin, $input, $startsAt, $setEndsAt, $endsAt): void {
            $announcement->update(
                null === $input->title ? null : trim($input->title),
                null === $input->body ? null : trim($input->body),
                $input->level,
                $startsAt,
                $setEndsAt,
                $endsAt,
            );
            $this->em->flush();
            $this->journal->record(ActivityType::AnnouncementUpdated, $admin, null, 'announcement', $announcement->getId(), ['level' => $announcement->getLevel()->value]);
        });

        return $this->mapper->announcement($announcement);
    }

    /** @param array<string, mixed> $uriVariables */
    private function find(array $uriVariables): Announcement
    {
        $raw = $uriVariables['announcementId'] ?? null;
        $announcement = \is_string($raw) && Uuid::isValid($raw) ? $this->em->find(Announcement::class, Uuid::fromString($raw)) : null;
        if (!$announcement instanceof Announcement) {
            throw ApiProblemException::notFound();
        }

        return $announcement;
    }

    private static function date(?string $value, string $path): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }
        if (1 !== preg_match(self::RFC3339, $value)) {
            throw ApiProblemException::validation($path, 'This value is not a valid RFC 3339 date-time.');
        }
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw ApiProblemException::validation($path, 'This value is not a valid RFC 3339 date-time.');
        }
        // Rejects overflow such as 2026-02-31 that PHP would silently roll over.
        if ($date->format('Y-m-d') !== substr($value, 0, 10)) {
            throw ApiProblemException::validation($path, 'This value is not a valid RFC 3339 date-time.');
        }

        return $date;
    }

    private static function checkRange(\DateTimeImmutable $startsAt, ?\DateTimeImmutable $endsAt): void
    {
        if (null !== $endsAt && $endsAt <= $startsAt) {
            throw ApiProblemException::validation('endsAt', 'endsAt must be after startsAt.');
        }
    }
}
