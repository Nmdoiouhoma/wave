<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Account\AccountMailer;
use App\Message\SendAccountDeletedEmail;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendAccountDeletedEmailHandler
{
    public function __construct(private AccountMailer $mailer)
    {
    }

    public function __invoke(SendAccountDeletedEmail $message): void
    {
        $this->mailer->sendAccountDeleted($message->email, $message->displayName);
    }
}
