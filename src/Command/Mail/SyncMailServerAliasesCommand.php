<?php

declare(strict_types=1);

namespace App\Command\Mail;

use App\Service\Mail\MailServerAliasSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:mail:sync-aliases',
    description: 'Write the aliases from Settings → Aliases to the self-hosted mail server.',
)]
final class SyncMailServerAliasesCommand extends Command
{
    public function __construct(private readonly MailServerAliasSync $sync)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->sync->sync();

        $output->writeln(null === $count
            ? 'No self-hosted mail server to tell (MAIL_DOMAIN unset or mail_config not mounted).'
            : sprintf('Wrote %d alias(es) for the mail server.', $count));

        return Command::SUCCESS;
    }
}
