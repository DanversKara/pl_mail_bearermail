<?php

declare(strict_types=1);

namespace App\Entity\Mail;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\User\User;
use App\Repository\Mail\SmtpKeyRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * An SMTP key: a scoped credential for scripts and automation to send mail
 * through the local Postfix, without the mailbox password.
 *
 * Unlike an ApiToken (which is a User-scoped JMAP credential), an SMTP key is
 * tied to one mail Account — it sends FROM that account's address. The key
 * itself is 32 bytes of CSPRNG output; only its SHA-256 hash is stored, and
 * the plaintext is shown exactly once at creation.
 *
 * The mta container syncs active keys from the shared volume
 * (/etc/plmail-mail-config/smtp_keys) into Dovecot's auth, so Postfix
 * accepts them on the submission port. Rate limits (per hour, per message)
 * are enforced by the mta's policy service.
 */
#[ORM\Entity(repositoryClass: SmtpKeyRepository::class)]
#[ORM\Table(name: 'smtp_key')]
#[ORM\UniqueConstraint(name: 'uniq_smtp_key_hash', columns: ['key_hash'])]
#[ORM\Index(name: 'idx_smtp_key_user', columns: ['usr_id'])]
#[ORM\Index(name: 'idx_smtp_key_account', columns: ['account_id'])]
#[ORM\Index(name: 'idx_smtp_key_active', columns: ['revoked_at'])]
#[ORM\HasLifecycleCallbacks]
class SmtpKey
{
    use TimestampableTrait;

    /**
     * Marks SMTP keys so they are greppable in logs and distinguishable
     * from ApiTokens at a glance.
     */
    public const string PREFIX = 'plmail_smtp_';

    /** Bytes of entropy behind the secret. */
    private const int SECRET_BYTES = 32;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'usr_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) User $usr;

    /** The mail account this key sends from. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'account_id', nullable: false, onDelete: 'CASCADE')]
    public private(set) Account $account;

    /** What the user called it, e.g. "Nightly backup script". */
    #[ORM\Column(length: 100)]
    public string $name {
        set {
            $this->name = mb_substr(trim($value), 0, 100);
        }
    }

    /** SHA-256 of the secret. The secret itself is never stored. */
    #[ORM\Column(name: 'key_hash', length: 64, unique: true)]
    public private(set) string $keyHash;

    /** First 8 chars of the secret, for identification in lists. */
    #[ORM\Column(name: 'key_prefix', length: 16)]
    public private(set) string $keyPrefix;

    /** Max messages per hour. 0 = unlimited. */
    #[ORM\Column(name: 'hourly_limit')]
    public int $hourlyLimit = 100;

    /** Max recipients per message. */
    #[ORM\Column(name: 'max_recipients')]
    public int $maxRecipients = 50;

    #[ORM\Column(name: 'last_used_at', nullable: true)]
    public ?DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(name: 'revoked_at', nullable: true)]
    public ?DateTimeImmutable $revokedAt = null;

    private function __construct()
    {
    }

    /**
     * @return array{key: SmtpKey, secret: string} The secret is shown once.
     */
    public static function create(User $user, Account $account, string $name): array
    {
        $secret = self::PREFIX . rtrim(base64_encode(random_bytes(self::SECRET_BYTES)), '=');

        $key = new self();
        $key->usr = $user;
        $key->account = $account;
        $key->name = $name;
        $key->keyHash = hash('sha256', $secret);
        $key->keyPrefix = substr($secret, 0, 16);

        return ['key' => $key, 'secret' => $secret];
    }

    public function revoke(): void
    {
        $this->revokedAt = new DateTimeImmutable();
    }

    public function isActive(): bool
    {
        return null === $this->revokedAt;
    }
}
