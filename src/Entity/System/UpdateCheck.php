<?php

declare(strict_types=1);

namespace App\Entity\System;

use App\Domain\Enum\System\UpdateChannel;
use App\Domain\Trait\TimestampableTrait;
use App\Repository\System\UpdateCheckRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Admin → Updates: which builds count as an update, and what the last check of
 * that channel found.
 *
 * One row, the way the other admin singletons are. The channel and whether to
 * install automatically are what an administrator sets, and all a config
 * backup carries. The rest is the checker's and the installer's own record,
 * and it would be wrong on any other installation.
 */
#[ORM\Entity(repositoryClass: UpdateCheckRepository::class)]
#[ORM\Table(name: 'update_check')]
// The singleton guarantee LogSettings and FcmConfig use: the only check that can
// hold against two concurrent first inserts is the index.
#[ORM\UniqueConstraint(name: 'uniq_update_check_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class UpdateCheck
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** Always 1; exists so the unique index above has a column to be unique over. */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    /**
     * The channel an administrator chose, or null to follow the one this build
     * came from (UpdateChannel::forBuild()).
     *
     * Null rather than the resolved value, for the reason LogSettings gives:
     * an installation that never chose keeps following its build across an
     * upgrade from `main` to a release, and storing the answer would freeze it.
     */
    #[ORM\Column(length: 16, nullable: true, enumType: UpdateChannel::class)]
    public ?UpdateChannel $channel = null;

    /** When the last check ran, answered or not. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $checkedAt = null;

    /** Why the last check got no answer; null when it did. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $error = null;

    /**
     * The last ANSWER, as App\Domain\DTO\System\UpdateStatus::toArray(). Kept
     * through a failed check, so an unreachable registry leaves the page
     * saying what it last knew and why it knows nothing newer.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
    public ?array $status = null;

    /**
     * The newest build administrators have been told about, so each build is
     * announced once however often the channel is checked.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $notifiedRevision = null;

    /**
     * Whether a newer build is installed without asking, or only announced.
     *
     * The app cannot install anything itself: it runs inside the containers an
     * install replaces. bin/auto-update, scheduled on the server, asks
     * app:updates:should-install every night, and this is what it is told.
     * Off by default: an install migrates the database on boot, so it happens
     * only where an administrator said so.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $autoInstall = false;

    /**
     * When bin/auto-update last asked, whatever it was told. Null means it has
     * never run on this server, which is what the page tells an administrator
     * who switched auto-install on and has not scheduled the script yet.
     */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $installerSeenAt = null;

    /** When bin/auto-update last finished an install, successfully or not. */
    #[ORM\Column(nullable: true)]
    public ?DateTimeImmutable $installedAt = null;

    /** Why the last install failed; null when it succeeded or none has run. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $installError = null;
}
