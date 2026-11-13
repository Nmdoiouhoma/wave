<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\ApiResource\ReportReceipt;
use App\Dto\Input\CreateReportInput;
use App\Entity\Report;
use App\Enum\ActivityType;
use App\Enum\Moderation;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Mapper\DateFormat;
use App\Moderation\ModerationApplier;
use App\Moderation\ReportTargetResolver;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Repository\ReportRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /reports` (201), DATA_MODEL §13. Check order: body (422) → target visible to the caller
 * with the rule of its type (else the same 404 as an unknown id) → own content (422
 * `cannot_report_own`) → duplicate (409 `already_reported`). Verified e-mail and the `report`
 * limit are enforced before (gate, listener).
 *
 * Insert and auto-hide run in one transaction under the target's advisory lock: the 5th distinct
 * open reporter hides a content target exactly once. The receipt is the same either way.
 *
 * @implements ProcessorInterface<mixed, ReportReceipt>
 */
final readonly class CreateReportProcessor implements ProcessorInterface
{
    public const int AUTO_HIDE_REPORTERS = 5;

    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private ReportTargetResolver $targets,
        private ReportRepository $reports,
        private ModerationApplier $moderation,
        private EntityManagerInterface $em,
        private ActivityJournal $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReportReceipt
    {
        $user = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, CreateReportInput::class);
        \assert(null !== $input->targetType && null !== $input->targetId && null !== $input->reason);
        $details = null === $input->details ? null : trim($input->details);

        $type = $input->targetType;
        $targetId = Uuid::fromString($input->targetId);
        $target = $this->targets->visibleTo($type, $targetId, $user);
        if (ReportTargetResolver::isOwn($target, $user)) {
            throw new ApiProblemException(ProblemCode::CannotReportOwn, 'You cannot report your own content.');
        }
        // Normalised id: the lock key and the stored id are the canonical spelling.
        $targetId = $target->getId();

        $report = new Report($user, $type, $targetId, $input->reason, '' === $details ? null : $details);
        /** @var array{bool, int} $outcome inserted, reporters when this report hid the target (else 0) */
        $outcome = $this->em->wrapInTransaction(function () use ($report, $type, $targetId): array {
            $this->reports->lockTarget($type, $targetId);
            if (!$this->reports->insertOpen($report)) {
                return [false, 0];
            }
            if (ReportTargetType::User !== $type && ($reporters = $this->reports->countOpenReporters($type, $targetId)) >= self::AUTO_HIDE_REPORTERS) {
                return [true, $this->moderation->setModeration($type, $targetId, Moderation::Hidden) ? $reporters : 0];
            }

            return [true, 0];
        });
        if (!$outcome[0]) {
            throw new ApiProblemException(ProblemCode::AlreadyReported, 'You already reported this.');
        }
        $owner = ReportTargetResolver::ownerOf($target)->getId();
        $this->journal->record(ActivityType::ReportCreated, $user, $owner, $type, $targetId, ['reason' => $input->reason]);
        if ($outcome[1] > 0) {
            $this->journal->record(ActivityType::ReportAutoHidden, $user, $owner, $type, $targetId, ['reportCount' => $outcome[1]]);
        }

        return new ReportReceipt($report->getId()->toRfc4122(), $report->getStatus()->value, DateFormat::format($report->getCreatedAt()));
    }
}
