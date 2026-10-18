<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\MailServerMailboxes;
use PHPUnit\Framework\TestCase;

/**
 * Mailboxes plMail adds to the built-in server after start-up: the file
 * Dovecot reads as a passdb and the mta folds into Postfix's mailbox map.
 */
final class MailServerMailboxesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/plmail-mailboxes-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    private function mailboxes(?string $domain = 'bearermail.com', string $env = 'paul:secret'): MailServerMailboxes
    {
        return new MailServerMailboxes($domain, $env, $this->dir . '/mailboxes');
    }

    public function testASavedMailboxIsAPasswdFileLineDovecotVerifies(): void
    {
        $mailboxes = $this->mailboxes();
        $mailboxes->save('Anna', 'correct horse');

        $line = trim((string) file_get_contents($this->dir . '/mailboxes'));
        self::assertStringStartsWith('anna@bearermail.com:{SHA512-CRYPT}$6$', $line);
        self::assertStringEndsWith('::::::', $line);

        $hash = substr(explode(':', $line)[1], \strlen('{SHA512-CRYPT}'));
        self::assertSame($hash, crypt('correct horse', $hash));
        self::assertTrue($mailboxes->exists('anna@bearermail.com'));
    }

    public function testAnAddressFromMailAccountsIsNeverWrittenASecondPassword(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->mailboxes()->save('paul', 'another password');
    }

    public function testOnlyLocalPartsOnTheServersDomainAreAccepted(): void
    {
        $mailboxes = $this->mailboxes();

        self::assertSame('anna.b@bearermail.com', $mailboxes->addressFor('anna.b'));
        self::assertSame('anna@bearermail.com', $mailboxes->addressFor('ANNA@bearermail.com'));
        self::assertNull($mailboxes->addressFor('anna@gmail.com'));
        self::assertNull($mailboxes->addressFor('an:na'));
        self::assertNull($mailboxes->addressFor('../etc'));
        self::assertNull($mailboxes->addressFor('anna bob'));
    }

    public function testListsBothSourcesAndRemovesOnlyItsOwn(): void
    {
        $mailboxes = $this->mailboxes();
        $mailboxes->save('anna', 'pw');

        self::assertSame(['paul@bearermail.com', 'anna@bearermail.com'], $mailboxes->existing());
        self::assertTrue($mailboxes->remove('anna'.'@bearermail.com'));
        self::assertFalse($mailboxes->remove('paul@bearermail.com'));
        self::assertSame(['paul@bearermail.com'], $mailboxes->existing());
    }

    public function testThereIsNoServerWithoutARealDomain(): void
    {
        self::assertFalse($this->mailboxes(null)->isAvailable());
        self::assertFalse($this->mailboxes('example.com')->isAvailable());
        self::assertTrue($this->mailboxes()->isAvailable());
    }
}
