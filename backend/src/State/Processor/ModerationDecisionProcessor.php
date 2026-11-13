<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\ApiResource\ReportTargetView;
use App\Dto\Input\ModerationDecisionInput;
use App\Entity\User;
use App\Enum\ActivityType;
use App\Enum\Moderation;
use App\Enum\ReportStatus;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Mapper\ReportMapper;
use App\Moderation\ModerationApplier;
use App\Moderation\ReportTargetResolver;
use App\Problem\ApiProblemException;
use App\Repository\ReportRepository;
use App\Security\CurrentUser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST /admin/report-targets/{targetType}/{targetId}/decision` (ROLE_MODERATOR, 200), DATA_MODEL
 * §13. Order: body (422) → unknown target (404) → action not allowed for the type, suspending
 * oneself (422 `validation_failed`) → suspending an admin, or a moderator when not admin (403,
 * ADR 013) → an already suspended user (422). `admin.report_decided` is journaled in the
 * decision's transaction. Applies to every open report
 * of the target, also with none (proactive moderation), under the target's advisory lock.
 *
 * Response: the target after the decision; counts and reasons are those of the reports holding
 * the resulting status (`actioned` or `dismissed`, as the queue with that `status` filter).
 *
 * @implements ProcessorInterface<mixed, ReportTargetView>
 */
final readonly class ModerationDecisionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private ReportTargetResolver $targets,
        private ReportRepository $reports,
        private ModerationApplier $moderation,
        private ReportMapper $mapper,
        private EntityManagerInterface $em,
        private ActivityJournal $journal,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ReportTargetView
    {
        $moderator = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, ModerationDecisionInput::class);
        [$type, $id] = ReportTargetResolver::fromPath($uriVariables['targetType'] ?? null, $uriVariables['targetId'] ?? null);
        $target = $this->targets->find($type, $id) ?? throw ApiProblemException::notFound();

        $action = (string) $input->action;
        $isUser = ReportTargetType::User === $type;
        if ($isUser ? ModerationDecisionInput::HIDE === $action : ModerationDecisionInput::SUSPEND === $action) {
            throw ApiProblemException::validation('action', $isUser ? 'A user is suspended, not hidden.' : 'Only a user can be suspended.');
        }
        if (ModerationDecisionInput::SUSPEND === $action && $target instanceof User) {
            if ($target->getId()->equals($moderator->getId())) {
                throw ApiProblemException::validation('action', 'You cannot suspend yourself.');
            }
            // ADR 013 §2: never an admin; a moderator only by an admin (W-37). After the 404.
            if ($target->isAdmin() || ($target->isModerator() && !$moderator->isAdmin())) {
                throw ApiProblemException::forbidden('You cannot suspend this account.');
            }
            if ($target->isSuspended()) {
                throw ApiProblemException::validation('action', 'This account is already suspended.');
            }
        }
        $note = null === $input->note || '' === trim($input->note) ? null : trim($input->note);
        $status = ModerationDecisionInput::DISMISS === $action ? ReportStatus::Dismissed : ReportStatus::Actioned;

        $this->em->wrapInTransaction(function () use ($type, $id, $target, $action, $status, $moderator, $note): void {
            $this->reports->lockTarget($type, $id);
            if ($target instanceof User) {
                if (ModerationDecisionInput::SUSPEND === $action) {
                    $this->moderation->suspend($target);
                }
            } else {
                $this->moderation->setModeration($type, $id, ModerationDecisionInput::HIDE === $action ? Moderation::Hidden : Moderation::Visible);
            }
            $closed = $this->reports->closeOpen($type, $id, $status, $moderator, $note);
            $this->journal->record(
                ActivityType::ReportDecided,
                $moderator,
                ReportTargetResolver::ownerOf($target),
                $type,
                $id,
                ['action' => $action, 'reportCount' => $closed, 'note' => $note],
            );
        });

        // The writes were native SQL: reload the target for the response, read again by id.
        // Deleted meanwhile (by its author, or a cascade, once the lock is released): refresh()
        // keeps the stale state or throws; either way the same 404 as an unknown id (F23).
        try {
            $this->em->refresh($target);
        } catch (EntityNotFoundException) {
            throw ApiProblemException::notFound();
        }
        if (!$this->targets->exists($type, $id)) {
            throw ApiProblemException::notFound();
        }
        $row = $this->reports->findTarget($type, $id, $status);
        $now = new \DateTimeImmutable();

        return $this->mapper->targetView(
            $type,
            $id->toRfc4122(),
            $target,
            $row['n'] ?? 0,
            $this->reports->reasonCounts($status, [$id->toRfc4122()])[$type->value.':'.$id->toRfc4122()] ?? [],
            $row['first_at'] ?? $now,
            $row['last_at'] ?? $now,
        );
    }
}
