<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Domain\Enum\System\UpdateChannel;
use App\Domain\Enum\System\UpdateVerdict;
use App\Service\System\Update\UpdateChecker;
use App\Service\System\Update\UpdateNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Asked by bin/auto-update on the server: should it install a newer build now?
 *
 * The answer is the exit code, because a shell script is what reads it:
 * 0 means install, NOTHING (3) means leave things as they are. Anything else is
 * this command failing, which the script also takes as "leave things as they
 * are": an installer that cannot get a clear yes does nothing.
 *
 * Yes only when an administrator switched auto-install on in Admin → Updates
 * and a fresh check of the channel finds a newer build than the one running.
 * Every call is recorded, so the page can tell an administrator whether the
 * script is scheduled on this server at all.
 */
#[AsCommand(
    name: 'app:updates:should-install',
    description: 'Exit 0 when bin/auto-update should install a newer build now, 3 when it should not.',
)]
final class ShouldInstallUpdateCommand extends Command
{
    public const int NOTHING = 3;

    public function __construct(
        private readonly UpdateChecker          $checker,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // A fresh answer rather than the hourly one: the build might have
        // been installed by hand since.
        $check = $this->checker->check();

        $check->installerSeenAt = new DateTimeImmutable();
        $this->entityManager->flush();

        if (false === $check->autoInstall) {
            $output->writeln('Auto-install is off in Admin → Updates; new builds are only announced.');

            return self::NOTHING;
        }

        $channel = $this->checker->channel($check);

        if (UpdateChannel::Off === $channel) {
            $output->writeln('Update checks are off in Admin → Updates.');

            return self::NOTHING;
        }

        if (null !== $check->error) {
            $output->writeln(sprintf('No answer from the %s channel: %s', $channel->value, $check->error));

            return self::NOTHING;
        }

        $status = $this->checker->status($check);

        if (null === $status || UpdateVerdict::Available !== $status->verdict) {
            $output->writeln(match ($status?->verdict) {
                UpdateVerdict::Current => sprintf('Up to date with %s.', $channel->value),
                UpdateVerdict::Unknown => 'This build has no commit stamped in, so there is nothing to compare; install by hand.',
                default                => 'No answer about this build yet.',
            });

            return self::NOTHING;
        }

        $output->writeln(sprintf('Install %s from %s.', UpdateNotifier::label($status->latest), $channel->value));

        return Command::SUCCESS;
    }
}
