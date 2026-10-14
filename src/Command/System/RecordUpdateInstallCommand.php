<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Repository\System\UpdateCheckRepository;
use App\Service\System\Update\UpdateChecker;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Told by bin/auto-update how an install went, so Admin → Updates can say.
 *
 * Run inside the containers the install just started, so after a success the
 * channel is checked again from the new build: the page then says "up to date"
 * instead of announcing the build that is already running.
 */
#[AsCommand(
    name: 'app:updates:installed',
    description: 'Record the outcome of an automatic update install (used by bin/auto-update).',
)]
final class RecordUpdateInstallCommand extends Command
{
    public function __construct(
        private readonly UpdateCheckRepository  $checks,
        private readonly UpdateChecker          $checker,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('failed', null, InputOption::VALUE_REQUIRED, 'What went wrong; omit when the install succeeded');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = trim((string) $input->getOption('failed'));
        $check  = $this->checks->currentOrNew();

        $check->installedAt  = new DateTimeImmutable();
        $check->installError = '' === $failed ? null : $failed;

        $this->entityManager->persist($check);
        $this->entityManager->flush();

        if (null === $check->installError) {
            $this->checker->check();
        }

        return Command::SUCCESS;
    }
}
