<?php

declare(strict_types=1);

namespace App\Entity\System;

use App\Repository\System\MailLoginAddressRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * BearerMail: one network address that has tried to sign in to the mail server
 * (IMAP on port 993) — how many times it failed lately, and whether it is
 * blocked or trusted.
 *
 * Written by MailLoginGuard from Dovecot's auth-policy reports; read by it
 * before every mail login. Admin → Security lists the blocked and trusted
 * addresses and can unblock one that was a mistake.
 *
 * Counters are updated with plain SQL (see the repository) so two failed
 * logins arriving at once both count.
 */
#[ORM\Entity(repositoryClass: MailLoginAddressRepository::class)]
#[ORM\Table(name: 'mail_login_address')]
#[ORM\UniqueConstraint(name: 'uniq_mail_login_address_ip', columns: ['ip'])]
#[ORM\Index(name: 'idx_mail_login_address_blocked', columns: ['blocked_at'])]
class MailLoginAddress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 45)]
    public string $ip = '';

    /** Failed logins since $windowStartedAt. */
    #[ORM\Column]
    public int $failures = 0;

    #[ORM\Column(name: 'window_started_at', nullable: true)]
    public ?DateTimeImmutable $windowStartedAt = null;

    #[ORM\Column(name: 'last_failure_at', nullable: true)]
    public ?DateTimeImmutable $lastFailureAt = null;

    /** The login name last tried from here (what the attacker guessed, or the user's typo). */
    #[ORM\Column(name: 'last_login', length: 255, nullable: true)]
    public ?string $lastLogin = null;

    /** Set while blocked. */
    #[ORM\Column(name: 'blocked_at', nullable: true)]
    public ?DateTimeImmutable $blockedAt = null;

    /** When the block ends by itself; null while blocked = until an admin unblocks it. */
    #[ORM\Column(name: 'blocked_until', nullable: true)]
    public ?DateTimeImmutable $blockedUntil = null;

    #[ORM\Column(name: 'times_blocked')]
    public int $timesBlocked = 0;

    /** Never blocked, whatever happens. */
    #[ORM\Column]
    public bool $trusted = false;

    #[ORM\Column(name: 'created_at')]
    public DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function isBlocked(DateTimeImmutable $now): bool
    {
        return false === $this->trusted
            && null !== $this->blockedAt
            && (null === $this->blockedUntil || $this->blockedUntil > $now);
    }
}
