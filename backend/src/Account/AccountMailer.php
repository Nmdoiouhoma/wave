<?php

declare(strict_types=1);

namespace App\Account;

use App\Entity\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Account emails, sent synchronously from Messenger handlers (CONVENTIONS §8).
 * Links point to the front pages, which strip the token from the URL then POST it.
 */
final readonly class AccountMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire(env: 'MAILER_FROM')]
        private string $from,
        #[Autowire(env: 'FRONT_PUBLIC_URL')]
        private string $frontUrl,
    ) {
    }

    public function sendVerification(User $user, string $rawToken): void
    {
        $this->send($user->getEmail(), 'Confirm your email address', 'verify_email', [
            'displayName' => $user->getDisplayName(),
            'url' => $this->link('/verify-email', $rawToken),
        ]);
    }

    public function sendPasswordReset(User $user, string $rawToken): void
    {
        $this->send($user->getEmail(), 'Reset your Wave password', 'password_reset', [
            'displayName' => $user->getDisplayName(),
            'url' => $this->link('/reset-password', $rawToken),
        ]);
    }

    public function sendAccountDeleted(string $email, string $displayName): void
    {
        $this->send($email, 'Your Wave account has been deleted', 'account_deleted', ['displayName' => $displayName]);
    }

    /** @param array<string, mixed> $context */
    private function send(string $to, string $subject, string $template, array $context): void
    {
        $email = new TemplatedEmail()
            ->from(Address::create($this->from))
            ->to($to)
            ->subject($subject)
            ->htmlTemplate("email/{$template}.html.twig")
            ->textTemplate("email/{$template}.txt.twig")
            ->context($context);
        $this->mailer->send($email);
    }

    private function link(string $path, string $rawToken): string
    {
        return rtrim($this->frontUrl, '/').$path.'?token='.rawurlencode($rawToken);
    }
}
