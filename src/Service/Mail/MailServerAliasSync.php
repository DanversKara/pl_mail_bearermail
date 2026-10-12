<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\EmailAlias;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells the self-hosted mail server about the aliases in Settings → Aliases.
 *
 * Writes /etc/plmail-mail-config/aliases (the shared volume) as
 * "alias@domain mailbox@domain" lines. The mta merges them into Postfix's
 * alias map, so mail to an alias lands in the mailbox, and lets that mailbox
 * send as the alias; it re-reads the file within ~15 seconds.
 *
 * Without this an alias was a From address only: the server had never heard
 * of it and bounced everything sent to it with "User unknown".
 *
 * Only aliases on MAIL_DOMAIN, belonging to an account that logs in to this
 * server as a mailbox on MAIL_DOMAIN, are written — a Gmail account's aliases
 * are Gmail's business. A disabled (inactive) alias is left out, so disabling
 * it in plMail also stops it receiving.
 *
 * Quietly does nothing when the shared volume is absent (no mail profile).
 */
final readonly class MailServerAliasSync
{
    private const string PATH = '/etc/plmail-mail-config/aliases';

    public function __construct(
        private EntityManagerInterface $em,
        #[Autowire('%env(default::MAIL_DOMAIN)%')]
        private ?string $mailDomain = null,
    ) {}

    /**
     * @return int|null aliases written, or null when there is no mail server to tell
     */
    public function sync(): ?int
    {
        $domain = strtolower(trim((string) $this->mailDomain));
        $dir = \dirname(self::PATH);

        if ('' === $domain || false === is_dir($dir) || false === is_writable($dir)) {
            return null;
        }

        $suffix = '@' . $domain;
        $lines = [];

        /** @var list<EmailAlias> $aliases */
        $aliases = $this->em->createQueryBuilder()
            ->select('a', 'acc')
            ->from(EmailAlias::class, 'a')
            ->join('a.account', 'acc')
            ->where('a.status != :inactive')
            ->setParameter('inactive', EmailAliasStatus::Inactive)
            ->getQuery()
            ->getResult();

        foreach ($aliases as $alias) {
            $address = strtolower(trim($alias->address));
            $mailbox = strtolower(trim((string) ($alias->account->username ?? '')));

            if (false === str_ends_with($address, $suffix) || false === str_ends_with($mailbox, $suffix)) {
                continue;
            }
            if ($address === $mailbox || 1 === preg_match('/\s/', $address . $mailbox)) {
                continue;
            }

            $lines[$address] = $address . ' ' . $mailbox;
        }

        ksort($lines);

        $content = implode("\n", $lines) . "\n";

        // Most saves of an Account change nothing here; skip the write then.
        if (is_file(self::PATH) && @file_get_contents(self::PATH) === $content) {
            return \count($lines);
        }

        $tmp = self::PATH . '.tmp';
        if (false === @file_put_contents($tmp, $content)) {
            return null;
        }
        @chmod($tmp, 0644);
        rename($tmp, self::PATH);

        return \count($lines);
    }
}
