<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\ReportReceipt;
use App\Dto\Input\CreateReportInput;
use App\Entity\Report;
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
        $inserted = $this->em->wrapInTransaction(function () use ($report, $type, $targetId): bool {
            $this->reports->lockTarget($type, $targetId);
            if (!$this->reports->insertOpen($report)) {
                return false;
            }
            if (ReportTargetType::User !== $type && $this->reports->countOpenReporters($type, $targetId) >= self::AUTO_HIDE_REPORTERS) {
                $this->moderation->setModeration($type, $targetId, Moderation::Hidden);
            }

            return true;
        });
        if (!$inserted) {
            throw new ApiProblemException(ProblemCode::AlreadyReported, 'You already reported this.');
        }

        return new ReportReceipt($report->getId()->toRfc4122(), $report->getStatus()->value, DateFormat::format($report->getCreatedAt()));
    }
}
