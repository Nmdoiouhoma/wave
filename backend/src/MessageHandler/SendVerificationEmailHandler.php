<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Account\AccountMailer;
use App\Account\AccountTokenIssuer;
use App\Enum\AccountTokenPurpose;
use App\Message\SendVerificationEmail;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SendVerificationEmailHandler
{
    public function __construct(
        private UserRepository $users,
        private AccountTokenIssuer $tokens,
        private AccountMailer $mailer,
    ) {
    }

    public function __invoke(SendVerificationEmail $message): void
    {
        $user = Uuid::isValid($message->userId) ? $this->users->find(Uuid::fromString($message->userId)) : null;
        if (null === $user || $user->isEmailVerified()) {
            return;
        }
        $raw = $this->tokens->issue($user, AccountTokenPurpose::EmailVerification);
        $this->mailer->sendVerification($user, $raw);
    }
}
