<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Account\AccountMailer;

/** W-66: French account emails must escape user-controlled values (displayName). */
final class EmailSecurityTest extends SecurityTestCase
{
    public function testDisplayNameIsEscapedInHtmlAndInertInText(): void
    {
        $evil = '<img src=x onerror=alert(1)>"\' %url% {{ 7*7 }}';
        $user = $this->createUser('mallory', displayName: mb_substr($evil, 0, 50));
        $mailer = static::getContainer()->get(AccountMailer::class);
        $mailer->sendVerification($user, 'RAWTOKEN');
        $mailer->sendPasswordReset($user, 'RAWTOKEN');
        $mailer->sendAccountDeleted('mallory@example.test', mb_substr($evil, 0, 50));

        $emails = $this->sentEmails();
        self::assertCount(3, $emails);
        foreach ($emails as $email) {
            $html = (string) $email->getHtmlBody();
            $text = (string) $email->getTextBody();
            self::assertStringNotContainsString('<img', $html);
            self::assertStringContainsString('&lt;img', $html);
            self::assertStringNotContainsString('49', $html.$text, 'no template evaluation of user data');
            // The placeholder inside the name is not expanded into a second link.
            self::assertLessThanOrEqual(1, substr_count($html, 'RAWTOKEN'));
            self::assertStringNotContainsString("\n", (string) $email->getSubject());
            self::assertSame(['mallory@example.test'], array_map(static fn ($a) => $a->getAddress(), $email->getTo()));
        }
    }
}
