<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Service\Mail\MailServerMailboxes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mailboxes on the built-in mail server, from the console.
 *
 *   app:mail:mailbox list
 *   app:mail:mailbox create anna            (asks for the password)
 *   app:mail:mailbox password anna          (new password for a mailbox plMail made)
 *   app:mail:mailbox remove anna            (stops logins and delivery; the Maildir stays)
 *   app:mail:mailbox from-accounts [--dry-run]
 *
 * "from-accounts" repairs people added before plMail could create mailboxes:
 * every mail account that signs in to the built-in server with an address on
 * MAIL_DOMAIN that has no mailbox gets one, with the password already saved in
 * that account — which is what they have been trying to sign in with all along.
 * Shell access to the host is the trust boundary here, as for app:user:password.
 */
#[AsCommand(
    name: 'app:mail:mailbox',
    description: 'Create, list, re-password or remove mailboxes on the built-in mail server.',
)]
final class MailboxCommand extends Command
{
    public function __construct(
        private readonly MailServerMailboxes $mailboxes,
        private readonly AccountRepository $accounts,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'list, create, password, remove or from-accounts')
            ->addArgument('address', InputArgument::OPTIONAL, 'Local part or full address on MAIL_DOMAIN')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'from-accounts: only show what would be created');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->mailboxes->domain()) {
            $io->error('MAIL_DOMAIN is not set, so there is no built-in mail server to manage.');

            return Command::FAILURE;
        }

        $action = (string) $input->getArgument('action');

        if ('list' === $action) {
            $rows = [];
            foreach ($this->mailboxes->existing() as $address) {
                $rows[] = [$address, $this->mailboxes->isFromEnvironment($address) ? 'MAIL_ACCOUNTS' : 'plMail'];
            }
            $io->table(['Mailbox', 'Managed by'], $rows);

            return Command::SUCCESS;
        }

        if (false === $this->mailboxes->isAvailable()) {
            $io->error('The mail_config volume is not mounted here, or not writable (/etc/plmail-mail-config).');

            return Command::FAILURE;
        }

        if ('from-accounts' === $action) {
            return $this->fromAccounts($io, true === $input->getOption('dry-run'));
        }

        $address = $this->mailboxes->addressFor((string) $input->getArgument('address'));
        if (null === $address) {
            $io->error(sprintf('Give a local part or an address on %s.', (string) $this->mailboxes->domain()));

            return Command::INVALID;
        }

        try {
            switch ($action) {
                case 'create':
                    if (true === $this->mailboxes->exists($address)) {
                        $io->error(sprintf('%s already exists.', $address));

                        return Command::FAILURE;
                    }
                    $this->mailboxes->save($address, $this->askPassword($io));
                    $io->success(sprintf('%s created. Add it in plMail with the "BearerMail (built-in server)" preset; it receives mail within about 15 seconds.', $address));

                    return Command::SUCCESS;

                case 'password':
                    if (false === $this->mailboxes->exists($address)) {
                        $io->error(sprintf('%s does not exist.', $address));

                        return Command::FAILURE;
                    }
                    $this->mailboxes->save($address, $this->askPassword($io));
                    $io->success(sprintf('New password set for %s. Update it in the plMail account that signs in with it.', $address));

                    return Command::SUCCESS;

                case 'remove':
                    if (true === $this->mailboxes->isFromEnvironment($address)) {
                        $io->error(sprintf('%s is in MAIL_ACCOUNTS; remove it there.', $address));

                        return Command::FAILURE;
                    }
                    if (false === $this->mailboxes->remove($address)) {
                        $io->error(sprintf('%s does not exist.', $address));

                        return Command::FAILURE;
                    }
                    $io->success(sprintf('%s can no longer sign in or receive mail. Its stored mail was left on disk.', $address));

                    return Command::SUCCESS;
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->error(sprintf('Unknown action "%s". Use list, create, password, remove or from-accounts.', $action));

        return Command::INVALID;
    }

    private function fromAccounts(SymfonyStyle $io, bool $dryRun): int
    {
        $created = [];
        $skipped = [];

        /** @var list<Account> $accounts */
        $accounts = $this->accounts->findBy(['authType' => 'password']);

        foreach ($accounts as $account) {
            if (false === MailServerMailboxes::isBuiltInServer($account->imapHost)) {
                continue;
            }

            $address = $this->mailboxes->addressFor((string) $account->username);
            if (null === $address) {
                $skipped[] = [(string) $account->id, (string) $account->username, 'not an address on ' . $this->mailboxes->domain()];

                continue;
            }
            if (true === $this->mailboxes->exists($address) || isset($created[$address])) {
                continue;
            }
            if (null === $account->password || '' === $account->password) {
                $skipped[] = [(string) $account->id, $address, 'no saved password'];

                continue;
            }

            if (false === $dryRun) {
                $this->mailboxes->save($address, $account->password);
            }
            $created[$address] = [(string) $account->id, $address];
        }

        if ([] !== $created) {
            $io->table(['Account', $dryRun ? 'Would create' : 'Created'], array_values($created));
        }
        if ([] !== $skipped) {
            $io->table(['Account', 'Address', 'Skipped because'], $skipped);
        }
        $io->success([] === $created
            ? 'Every account on the built-in server already has a mailbox.'
            : sprintf('%d mailbox(es) %s. They can sign in now and receive mail within about 15 seconds.', \count($created), $dryRun ? 'would be created' : 'created'));

        return Command::SUCCESS;
    }

    private function askPassword(SymfonyStyle $io): string
    {
        $password = (string) $io->askHidden('Password for the mailbox');
        $again = (string) $io->askHidden('Again');

        if ($password !== $again) {
            throw new \InvalidArgumentException('The passwords did not match.');
        }

        return $password;
    }
}
