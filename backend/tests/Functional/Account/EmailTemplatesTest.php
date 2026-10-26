<?php

declare(strict_types=1);

namespace App\Tests\Functional\Account;

use App\Account\AccountMailer;
use App\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/**
 * W-65 (FB-03): French subjects and bodies. Snapshots live in snapshots/; to update them
 * after a wording change, delete the file and run the test once (it writes it and fails).
 */
final class EmailTemplatesTest extends KernelTestCase
{
    /** @return iterable<string, array{string, array<string, string>}> */
    public static function templates(): iterable
    {
        $ctx = ['displayName' => 'Zoé <b>', 'url' => 'http://front.test/verify-email?token=abc_DEF-123'];
        yield 'verify_email' => ['verify_email', $ctx];
        yield 'password_reset' => ['password_reset', ['url' => 'http://front.test/reset-password?token=abc_DEF-123'] + $ctx];
        yield 'account_deleted' => ['account_deleted', ['displayName' => 'Zoé <b>']];
    }

    /** @param array<string, string> $context */
    #[DataProvider('templates')]
    public function testTemplateMatchesSnapshot(string $name, array $context): void
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = static::getContainer()->get('twig');
        foreach (['html', 'txt'] as $format) {
            $rendered = $twig->render("email/{$name}.{$format}.twig", $context);
            $file = __DIR__."/snapshots/{$name}.{$format}";
            if (!is_file($file)) {
                file_put_contents($file, $rendered);
                self::fail("Snapshot {$file} written, check it and run again.");
            }
            self::assertSame(file_get_contents($file), $rendered, "{$name}.{$format}");
            self::assertStringNotContainsString('<b>', 'html' === $format ? $rendered : '', 'HTML escapes user data');
        }
    }

    public function testSubjectsAreFrenchAndLinksUnchanged(): void
    {
        self::bootKernel();
        $mailer = static::getContainer()->get(AccountMailer::class);
        $user = new User('zoe@example.test', 'zoe', 'Zoé');

        $mailer->sendVerification($user, 'tok_1');
        $mailer->sendPasswordReset($user, 'tok_2');
        $mailer->sendAccountDeleted('zoe@example.test', 'Zoé');

        /** @var \Symfony\Component\Mailer\EventListener\MessageLoggerListener $logger */
        $logger = static::getContainer()->get('mailer.message_logger_listener'); // @phpstan-ignore symfonyContainer.serviceNotFound (test env only)
        $emails = array_values(array_filter($logger->getEvents()->getMessages(), static fn ($m): bool => $m instanceof Email));
        self::assertSame(
            ['Confirme ton adresse e-mail', 'Réinitialise ton mot de passe Wave', 'Ton compte Wave a été supprimé'],
            array_map(static fn (Email $e): ?string => $e->getSubject(), $emails),
        );
        self::assertStringContainsString('http://front.test/verify-email?token=tok_1', (string) $emails[0]->getTextBody());
        self::assertStringContainsString('http://front.test/reset-password?token=tok_2', (string) $emails[1]->getHtmlBody());
        self::assertStringContainsString('Bonjour Zoé,', (string) $emails[2]->getTextBody());
    }
}
