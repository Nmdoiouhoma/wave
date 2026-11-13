<?php

declare(strict_types=1);

namespace App\Command\Admin;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The only way to create an admin (ADR 013). Unknown email: "User not found.", exit 1; a
 * suspended or unverified account is refused, exit 1 (W-77 F27).
 */
#[AsCommand(name: 'app:admin:grant', description: 'Grant ROLE_ADMIN to an account, by email')]
final readonly class GrantAdminCommand
{
    public function __construct(private AdminRoleChanger $changer)
    {
    }

    public function __invoke(OutputInterface $output, #[Argument(description: 'Email of the account')] string $email): int
    {
        return match ($this->changer->change($email, true)) {
            AdminRoleOutcome::Changed => self::say($output, 'ROLE_ADMIN granted.', Command::SUCCESS),
            AdminRoleOutcome::Unchanged => self::say($output, 'This account is already an admin.', Command::SUCCESS),
            AdminRoleOutcome::Suspended => self::say($output, 'Refused: this account is suspended.', Command::FAILURE),
            AdminRoleOutcome::Unverified => self::say($output, 'Refused: this account has not verified its email.', Command::FAILURE),
            AdminRoleOutcome::NotFound, AdminRoleOutcome::LastAdmin => self::say($output, 'User not found.', Command::FAILURE),
        };
    }

    private static function say(OutputInterface $output, string $line, int $code): int
    {
        $output->writeln($line);

        return $code;
    }
}
