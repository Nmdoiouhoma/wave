<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Account\AccountMailer;
use App\Account\AccountTokenIssuer;
use App\Activity\ActivityJournal;
use App\Enum\AccountTokenPurpose;
use App\Enum\ActivityType;
use App\Message\SendPasswordResetEmail;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/** ADR 007: existence is only ever checked here, in the worker. Journal: DATA_MODEL §15. */
#[AsMessageHandler]
final readonly class SendPasswordResetEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private AccountTokenIssuer $tokens,
        private AccountMailer $mailer,
        private ActivityJournal $journal,
    ) {
    }

    public function __invoke(SendPasswordResetEmail $message): void
    {
        $user = $this->users->findOneByEmail($message->email);
        if (null === $user || $user->isSuspended()) {
            return;
        }
        $raw = $this->tokens->issue($user, AccountTokenPurpose::PasswordReset);
        $this->mailer->sendPasswordReset($user, $raw);
        // Recorded here, where the account is known (the endpoint answers the same 202 for any email):
        // no IP or user agent (worker).
        $this->journal->record(ActivityType::PasswordResetRequested, null, $user);
    }
}
