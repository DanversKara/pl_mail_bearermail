<?php

declare(strict_types=1);

namespace App\Entity\System;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\System\SecurityEventRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * A security-relevant event: logins, failed logins, password changes, etc.
 *
 * Written by event listeners across the app. The admin Security page shows
 * recent events, highlighting suspicious patterns (many failed logins from
 * one IP, logins from new locations).
 */
#[ORM\Entity(repositoryClass: SecurityEventRepository::class)]
#[ORM\Table(name: 'security_event')]
#[ORM\Index(name: 'idx_security_event_created', columns: ['created_at'])]
#[ORM\Index(name: 'idx_security_event_ip', columns: ['ip_address'])]
#[ORM\Index(name: 'idx_security_event_type_created', columns: ['type', 'created_at'])]
#[ORM\HasLifecycleCallbacks]
class SecurityEvent
{
    use TimestampableTrait;

    public const string TYPE_LOGIN_SUCCESS = 'login_success';
    public const string TYPE_LOGIN_FAILURE = 'login_failure';
    public const string TYPE_PASSWORD_CHANGE = 'password_change';
    public const string TYPE_2FA_ENABLED = '2fa_enabled';
    public const string TYPE_2FA_DISABLED = '2fa_disabled';
    public const string TYPE_API_TOKEN_CREATED = 'api_token_created';
    public const string TYPE_API_TOKEN_REVOKED = 'api_token_revoked';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: true, onDelete: 'SET NULL')]
    public ?User $usr = null;

    /** One of the TYPE_* constants. */
    #[ORM\Column(length: 50)]
    public string $type;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    public ?string $ipAddress = null;

    /** User agent or other context. */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $details = null;

    public static function create(string $type, ?User $user = null, ?string $ip = null, ?string $details = null): self
    {
        $event = new self();
        $event->type = $type;
        $event->usr = $user;
        $event->ipAddress = $ip;
        $event->details = $details;

        return $event;
    }
}
