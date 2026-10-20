<?php

declare(strict_types=1);

namespace App\Command\System;

use App\Service\Security\SignInProtection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * BearerMail: the way back into Admin when the home address changed and the
 * network lock (Admin → Security → Sign-in protection) answers 403.
 *
 *   docker compose exec php bin/console app:security:admin-unlock
 */
#[AsCommand(
    name: 'app:security:admin-unlock',
    description: 'Switch off "Admin only from these networks" so Admin opens from anywhere again.',
)]
final class AdminUnlockCommand extends Command
{
    public function __construct(
        private readonly SignInProtection $protection,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->protection->save(['adminLock' => false]);
        new SymfonyStyle($input, $output)->success('Admin opens from any address again. Turn the lock back on under Admin → Security.');

        return Command::SUCCESS;
    }
}
