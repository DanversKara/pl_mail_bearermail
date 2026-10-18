<?php

declare(strict_types=1);

namespace App\Service\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Mailboxes on the self-hosted mail server that plMail created, as opposed to
 * the ones listed in MAIL_ACCOUNTS.
 *
 * MAIL_ACCOUNTS is read once, when the mta and mailstore containers start, so
 * a person added to plMail afterwards had no mailbox at all: Dovecot answered
 * every login with AUTHENTICATIONFAILED and Postfix bounced their mail as
 * "User unknown". This file is the second list, kept by plMail on the shared
 * volume:
 *
 *   /etc/plmail-mail-config/mailboxes
 *   anna@example.com:{SHA512-CRYPT}$6$…::::::
 *
 * Dovecot reads it as a passdb on every login, so a new mailbox can sign in at
 * once; the mta's watcher folds it into Postfix's mailbox map within ~15
 * seconds, so it starts receiving and may send as itself.
 *
 * An address that is already in MAIL_ACCOUNTS is refused: Dovecot tries the
 * next passdb when a password does not match, so a second entry with another
 * password would be a second way into that mailbox.
 */
final readonly class MailServerMailboxes
{
    public const string PATH = '/etc/plmail-mail-config/mailboxes';

    /** The built-in server as the app reaches it inside the compose network (config/mail_presets.yaml "bearermail"). */
    public const string IMAP_HOST = 'mailstore';
    public const int IMAP_PORT = 143;
    public const string SMTP_HOST = 'mta';
    public const int SMTP_PORT = 587;

    public function __construct(
        #[Autowire('%env(default::MAIL_DOMAIN)%')]
        private ?string $mailDomain = null,
        #[Autowire('%env(default::MAIL_ACCOUNTS)%')]
        private ?string $mailAccounts = null,
        private string $path = self::PATH,
    ) {}

    /** The domain mailboxes are created on, or null when there is no built-in mail server. */
    public function domain(): ?string
    {
        $domain = strtolower(trim((string) $this->mailDomain));

        // "example.com" is the containers' unset sentinel, not a real domain.
        return '' === $domain || 'example.com' === $domain ? null : $domain;
    }

    /** True when there is a domain and the shared volume is mounted and writable. */
    public function isAvailable(): bool
    {
        $dir = \dirname($this->path);

        return null !== $this->domain() && is_dir($dir) && is_writable($dir);
    }

    /**
     * "anna" or "anna@example.com" → "anna@example.com"; null when it is not a
     * usable local part on this server's domain.
     */
    public function addressFor(string $localPartOrAddress): ?string
    {
        $domain = $this->domain();
        if (null === $domain) {
            return null;
        }

        $value = strtolower(trim($localPartOrAddress));
        if (str_contains($value, '@')) {
            [$local, $host] = explode('@', $value, 2);
            if ($host !== $domain) {
                return null;
            }
            $value = $local;
        }

        // Conservative: what is safe as a Maildir folder name, in Postfix's
        // text maps and in Dovecot's colon-separated passwd-file.
        if (1 !== preg_match('/^[a-z0-9](?:[a-z0-9._+-]{0,62}[a-z0-9])?$/', $value) || str_contains($value, '..')) {
            return null;
        }

        return $value . '@' . $domain;
    }

    /**
     * Every address that already has a mailbox: MAIL_ACCOUNTS first, then this file.
     *
     * @return list<string>
     */
    public function existing(): array
    {
        return array_values(array_unique([...$this->envAddresses(), ...array_keys($this->read())]));
    }

    public function exists(string $address): bool
    {
        return \in_array(strtolower(trim($address)), $this->existing(), true);
    }

    /** True for a mailbox from MAIL_ACCOUNTS, which plMail cannot change. */
    public function isFromEnvironment(string $address): bool
    {
        return \in_array(strtolower(trim($address)), $this->envAddresses(), true);
    }

    /**
     * Creates the mailbox, or sets a new password on one plMail made earlier.
     *
     * @throws \InvalidArgumentException for an unusable address or one from MAIL_ACCOUNTS
     * @throws \RuntimeException         when there is no mail server to write to
     */
    public function save(string $address, string $password): void
    {
        $normalized = $this->addressFor($address);
        if (null === $normalized) {
            throw new \InvalidArgumentException(sprintf('"%s" is not an address this mail server can host.', $address));
        }
        if ($this->isFromEnvironment($normalized)) {
            throw new \InvalidArgumentException(sprintf('%s is in MAIL_ACCOUNTS; change its password there.', $normalized));
        }
        if ('' === $password || 1 === preg_match('/[\r\n]/', $password)) {
            throw new \InvalidArgumentException('The mailbox password must not be empty or contain line breaks.');
        }
        if (false === $this->isAvailable()) {
            throw new \RuntimeException('No self-hosted mail server to write to (MAIL_DOMAIN unset or mail_config not mounted).');
        }

        $entries = $this->read();
        $entries[$normalized] = $this->hash($password);
        $this->write($entries);
    }

    public function remove(string $address): bool
    {
        $entries = $this->read();
        $key = strtolower(trim($address));
        if (false === isset($entries[$key])) {
            return false;
        }

        unset($entries[$key]);
        $this->write($entries);

        return true;
    }

    /** Whether a password-authenticated account points at the built-in server. */
    public static function isBuiltInServer(?string $imapHost): bool
    {
        return self::IMAP_HOST === strtolower(trim((string) $imapHost));
    }

    /**
     * @return array<string, string> address => hash
     */
    private function read(): array
    {
        if (false === is_file($this->path)) {
            return [];
        }

        $entries = [];
        foreach (file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $parts = explode(':', $line);
            if (\count($parts) < 2 || '' === $parts[0]) {
                continue;
            }
            $entries[strtolower($parts[0])] = $parts[1];
        }

        return $entries;
    }

    /**
     * @param array<string, string> $entries
     */
    private function write(array $entries): void
    {
        ksort($entries);

        $lines = [];
        foreach ($entries as $address => $hash) {
            $lines[] = $address . ':' . $hash . '::::::';
        }

        $tmp = $this->path . '.tmp';
        if (false === file_put_contents($tmp, [] === $lines ? '' : implode("\n", $lines) . "\n")) {
            throw new \RuntimeException(sprintf('Could not write %s', $tmp));
        }
        // Dovecot's auth process must read it; the lines are salted SHA-512 crypt hashes.
        @chmod($tmp, 0644);
        rename($tmp, $this->path);
    }

    private function hash(string $password): string
    {
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);

        return '{SHA512-CRYPT}' . crypt($password, '$6$rounds=5000$' . $salt . '$');
    }

    /**
     * @return list<string>
     */
    private function envAddresses(): array
    {
        $domain = $this->domain();
        if (null === $domain) {
            return [];
        }

        $addresses = [];
        foreach (explode(',', (string) $this->mailAccounts) as $pair) {
            $user = strtolower(trim(explode(':', $pair, 2)[0]));
            if ('' !== $user && str_contains($pair, ':')) {
                $addresses[] = $user . '@' . $domain;
            }
        }

        return $addresses;
    }
}
