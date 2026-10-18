<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\User\User;

/**
 * Gives a person a working mailbox on the built-in mail server: creates it in
 * MailServerMailboxes, then adds it to their plMail as a mail account
 * (the "BearerMail (built-in server)" preset), so they can send and receive
 * the first time they sign in — nothing to type, nothing for an admin to add
 * to MAIL_ACCOUNTS and no container restart.
 */
final readonly class MailboxProvisioner
{
    public function __construct(
        private MailServerMailboxes $mailboxes,
        private AccountCreator $accountCreator,
    ) {}

    /**
     * @throws \InvalidArgumentException when the address is unusable or already exists
     * @throws \RuntimeException         when there is no built-in mail server
     */
    public function provision(User $user, string $localPartOrAddress, string $password): Account
    {
        $address = $this->mailboxes->addressFor($localPartOrAddress);
        if (null === $address) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an address this mail server can host.', $localPartOrAddress));
        }
        if (true === $this->mailboxes->exists($address)) {
            throw new \InvalidArgumentException(sprintf('%s already exists on the mail server.', $address));
        }

        $this->mailboxes->save($address, $password);

        $account = $this->accountCreator->blank();
        $account->name = trim(sprintf('%s %s', (string) $user->nameFirst, (string) $user->nameLast)) ?: $address;
        $account->email = $address;
        $account->username = $address;
        $account->password = $password;
        $account->imapHost = MailServerMailboxes::IMAP_HOST;
        $account->imapPort = MailServerMailboxes::IMAP_PORT;
        $account->imapEncryption = 'none';
        $account->smtpHost = MailServerMailboxes::SMTP_HOST;
        $account->smtpPort = MailServerMailboxes::SMTP_PORT;
        $account->smtpEncryption = 'none';

        $this->accountCreator->create($account, $user);

        return $account;
    }
}
