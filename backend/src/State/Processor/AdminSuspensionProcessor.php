<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\DeleteOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\Admin\AdminQueryParams;
use App\Admin\AdminUserMapper;
use App\Admin\StaffGuard;
use App\ApiResource\AdminUserDetailView;
use App\Dto\Input\AdminNoteInput;
use App\Dto\Input\AdminOptionalNoteInput;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Moderation\ModerationApplier;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `POST|DELETE /admin/users/{userId}/suspension` (ROLE_MODERATOR, ADR 013, DATA_MODEL §13).
 * Order: body (422) → unknown id (404) → self (422) → role guard (403) → already suspended /
 * not suspended (422). Suspension = `suspended_at = now()` + every refresh-token family deleted;
 * unsuspension clears `suspended_at` (no session restored). One `admin.user_suspended` /
 * `admin.user_unsuspended` row in the same transaction; the conditional UPDATE makes two
 * concurrent calls change and journal the account once (the loser gets the 422).
 *
 * @implements ProcessorInterface<mixed, AdminUserDetailView>
 */
final readonly class AdminSuspensionProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private AdminQueryParams $params,
        private ModerationApplier $moderation,
        private Connection $db,
        private EntityManagerInterface $em,
        private ActivityJournal $journal,
        private AdminUserMapper $mapper,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AdminUserDetailView
    {
        $caller = $this->currentUser->require();
        /** @var Request $request */
        $request = $context['request'];
        $suspend = !$operation instanceof DeleteOperationInterface;
        $note = $suspend
            ? $this->decoder->decode($request, AdminNoteInput::class)->note
            : $this->decoder->decode($request, AdminOptionalNoteInput::class, true)->note;
        $note = null === $note || '' === trim($note) ? null : trim($note);

        $target = $this->params->user($uriVariables['userId'] ?? null);
        if ($target->getId()->equals($caller->getId())) {
            throw ApiProblemException::validation('userId', $suspend ? 'You cannot suspend yourself.' : 'You cannot unsuspend yourself.');
        }
        if (!StaffGuard::canActOn($caller, $target)) {
            throw ApiProblemException::forbidden($suspend ? 'You cannot suspend this account.' : 'You cannot unsuspend this account.');
        }
        $notInState = static fn (): ApiProblemException => ApiProblemException::validation('userId', $suspend ? 'This account is already suspended.' : 'This account is not suspended.');
        if ($suspend === $target->isSuspended()) {
            throw $notInState();
        }

        $this->em->wrapInTransaction(function () use ($caller, $target, $suspend, $note, $notInState): void {
            $changed = $suspend
                ? $this->moderation->suspend($target)
                : 1 === (int) $this->db->executeStatement(
                    'UPDATE app_user SET suspended_at = NULL WHERE id = :id AND suspended_at IS NOT NULL',
                    ['id' => $target->getId()->toRfc4122()],
                );
            if (!$changed) {
                throw $notInState();
            }
            $this->journal->record(
                $suspend ? ActivityType::UserSuspended : ActivityType::UserUnsuspended,
                $caller,
                $target,
                ReportTargetType::User,
                $target->getId(),
                ['note' => $note],
            );
        });
        // The write was native SQL: reload the entity for the response.
        $this->em->refresh($target);

        return $this->mapper->toDetail($target, $caller->isAdmin());
    }
}
