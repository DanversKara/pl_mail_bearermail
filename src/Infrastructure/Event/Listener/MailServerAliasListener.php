<?php

declare(strict_types=1);

namespace App\Infrastructure\Event\Listener;

use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Service\Mail\MailServerAliasSync;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Re-writes the mail server's alias file whenever an alias (or an account,
 * which carries the mailbox login) is added, changed or removed — from
 * Settings → Aliases, "Refresh from provider", a JMAP Identity/set or a
 * restore. One hook on the data instead of one call in each of those places,
 * so a new way of editing aliases cannot forget to tell the server.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class MailServerAliasListener
{
    private bool $dirty = false;

    public function __construct(private readonly MailServerAliasSync $sync) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof EmailAlias || $entity instanceof Account) {
                    $this->dirty = true;

                    return;
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (false === $this->dirty) {
            return;
        }
        $this->dirty = false;

        try {
            $this->sync->sync();
        } catch (\Throwable) {
            // Never fail a save because the mail server could not be told;
            // the scheduled sync (every five minutes) catches up.
        }
    }
}
