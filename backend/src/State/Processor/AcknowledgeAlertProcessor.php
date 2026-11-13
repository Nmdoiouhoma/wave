<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\Activity\AdminQuery;
use App\ApiResource\SecurityAlertView;
use App\Dto\Input\AcknowledgeAlertInput;
use App\Entity\SecurityAlert;
use App\Enum\ActivityType;
use App\Enum\AlertStatus;
use App\Http\JsonInputDecoder;
use App\Mapper\JournalMapper;
use App\Problem\ApiProblemException;
use App\Problem\ProblemCode;
use App\Security\CurrentUser;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;

/**
 * `POST /admin/alerts/{alertId}/acknowledgement` (ROLE_ADMIN, 200). Order: moderator → 403
 * (before any lookup) → body → 422 → unknown id → 404 → already acknowledged → 409
 * `already_acknowledged`. The row is locked: of two concurrent acknowledgements one wins, the
 * other gets 409. `admin.alert_acknowledged` (rule, note) is journaled in the same transaction.
 *
 * @implements ProcessorInterface<mixed, SecurityAlertView>
 */
final readonly class AcknowledgeAlertProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private EntityManagerInterface $em,
        private ActivityJournal $journal,
        private JournalMapper $mapper,
        private ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SecurityAlertView
    {
        $admin = AdminQuery::requireAdmin($this->currentUser->require());
        /** @var Request $request */
        $request = $context['request'];
        $input = $this->decoder->decode($request, AcknowledgeAlertInput::class);
        $note = trim((string) $input->note);
        $raw = $uriVariables['alertId'] ?? null;
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw ApiProblemException::notFound();
        }
        $id = Uuid::fromString($raw);

        $alert = $this->em->wrapInTransaction(function () use ($id, $admin, $note): SecurityAlert {
            $alert = $this->em->find(SecurityAlert::class, $id, LockMode::PESSIMISTIC_WRITE);
            if (!$alert instanceof SecurityAlert) {
                throw ApiProblemException::notFound();
            }
            if (AlertStatus::Open !== $alert->getStatus()) {
                throw new ApiProblemException(ProblemCode::AlreadyAcknowledged, 'This alert is already acknowledged.');
            }
            $alert->acknowledge($admin, $note, $this->clock->now());
            $this->em->flush();
            $this->journal->record(
                ActivityType::AlertAcknowledged,
                $admin,
                $alert->getSubjectUser(),
                'alert',
                $alert->getId(),
                ['rule' => $alert->getRule(), 'note' => $note],
            );

            return $alert;
        });

        return $this->mapper->alert($alert);
    }
}
