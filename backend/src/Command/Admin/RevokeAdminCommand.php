<?php

declare(strict_types=1);

namespace App\Command\Admin;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/** Removes ROLE_ADMIN, keeps the other roles; the last admin cannot be revoked (ADR 013). */
#[AsCommand(name: 'app:admin:revoke', description: 'Revoke ROLE_ADMIN from an account, by email')]
final readonly class RevokeAdminCommand
{
    public function __construct(private AdminRoleChanger $changer)
    {
    }

    public function __invoke(OutputInterface $output, #[Argument(description: 'Email of the account')] string $email): int
    {
        return match ($this->changer->change($email, false)) {
            AdminRoleOutcome::Changed => self::say($output, 'ROLE_ADMIN revoked.', Command::SUCCESS),
            AdminRoleOutcome::Unchanged => self::say($output, 'This account is not an admin.', Command::SUCCESS),
            AdminRoleOutcome::LastAdmin => self::say($output, 'Refused: this is the last admin.', Command::FAILURE),
            AdminRoleOutcome::NotFound, AdminRoleOutcome::Suspended, AdminRoleOutcome::Unverified => self::say($output, 'User not found.', Command::FAILURE),
        };
    }

    private static function say(OutputInterface $output, string $line, int $code): int
    {
        $output->writeln($line);

        return $code;
    }
}
