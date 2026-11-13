<?php

declare(strict_types=1);

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Activity\ActivityJournal;
use App\Admin\AdminQueryParams;
use App\Admin\AdminUserMapper;
use App\ApiResource\AdminUserDetailView;
use App\Dto\Input\SetRoleInput;
use App\Enum\ActivityType;
use App\Enum\ReportTargetType;
use App\Http\JsonInputDecoder;
use App\Problem\ApiProblemException;
use App\Security\CurrentUser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * `PUT /admin/users/{userId}/role` (ROLE_ADMIN, ADR 013 §3): moves a user between `ROLE_USER`
 * and `ROLE_MODERATOR`, never to or from `ROLE_ADMIN` (console only).
 * Order: body (422, `ROLE_ADMIN` included) → unknown id (404) → self or an admin target (403) →
 * same role (200 unchanged, nothing journaled). The row is locked (`FOR UPDATE`) and re-read in
 * the transaction, so a concurrent `app:admin:grant` cannot be overwritten. Roles are read from
 * the database on every request: the change applies at the target's next request.
 *
 * @implements ProcessorInterface<mixed, AdminUserDetailView>
 */
final readonly class AdminRoleProcessor implements ProcessorInterface
{
    public function __construct(
        private CurrentUser $currentUser,
        private JsonInputDecoder $decoder,
        private AdminQueryParams $params,
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
        $input = $this->decoder->decode($request, SetRoleInput::class);
        $to = (string) $input->role;
        $note = null === $input->note || '' === trim($input->note) ? null : trim($input->note);

        $target = $this->params->user($uriVariables['userId'] ?? null);
        if ($target->getId()->equals($caller->getId()) || $target->isAdmin()) {
            throw ApiProblemException::forbidden('You cannot change the role of this account.');
        }

        $this->em->wrapInTransaction(function () use ($caller, $target, $to, $note): void {
            $raw = $this->db->fetchOne('SELECT roles FROM app_user WHERE id = :id FOR UPDATE', ['id' => $target->getId()->toRfc4122()]);
            if (false === $raw) {
                throw ApiProblemException::notFound();
            }
            /** @var list<string> $roles */
            $roles = json_decode((string) $raw, true, 4, \JSON_THROW_ON_ERROR);
            if (\in_array('ROLE_ADMIN', $roles, true)) {
                throw ApiProblemException::forbidden('You cannot change the role of this account.');
            }
            $from = \in_array(SetRoleInput::MODERATOR, $roles, true) ? SetRoleInput::MODERATOR : SetRoleInput::USER;
            if ($from === $to) {
                return;
            }
            $roles = array_values(array_diff($roles, [SetRoleInput::MODERATOR, SetRoleInput::USER]));
            if (SetRoleInput::MODERATOR === $to) {
                $roles[] = SetRoleInput::MODERATOR;
            }
            $this->db->executeStatement(
                'UPDATE app_user SET roles = CAST(:roles AS json) WHERE id = :id',
                ['roles' => json_encode($roles, \JSON_THROW_ON_ERROR), 'id' => $target->getId()->toRfc4122()],
            );
            $this->journal->record(ActivityType::RoleChanged, $caller, $target, ReportTargetType::User, $target->getId(), ['from' => $from, 'to' => $to, 'note' => $note]);
        });
        $this->em->refresh($target);

        return $this->mapper->toDetail($target, $caller->isAdmin());
    }
}
