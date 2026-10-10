<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Repository\Mail\SmtpKeyRepository;

/**
 * Syncs active SMTP keys to the mta container via the shared volume.
 *
 * Writes /etc/plmail-mail-config/smtp_keys in Dovecot passwd-file format:
 *   smtp_42:{SHA256.HEX}<hash>:5000:5000::/nonexistent
 *
 * Dovecot reads it as a second passdb on every auth, so a change applies at
 * once. Postfix submission authenticates against Dovecot, which is how a key
 * is accepted on port 587. The
 * username is `smtp_<id>`; the password is the full secret shown once at
 * creation. Dovecot verifies by hashing the presented secret with SHA-256 —
 * HEX, because that is how the hash is stored; plain {SHA256} means base64 to
 * Dovecot, and no key ever authenticated.
 *
 * Revoked keys are simply absent from the file.
 */
final readonly class SmtpKeySync
{
    private const string KEYS_PATH = '/etc/plmail-mail-config/smtp_keys';

    /**
     * Which From address each key may use: "address smtp_1,smtp_7". The mta
     * merges it into smtpd_sender_login_maps, so a key made for one mailbox
     * cannot send as another — or as anybody else on the domain.
     */
    private const string SENDERS_PATH = '/etc/plmail-mail-config/smtp_key_senders';

    public function __construct(
        private SmtpKeyRepository $keys,
    ) {}

    public function sync(): void
    {
        $dir = \dirname(self::KEYS_PATH);
        if (false === is_dir($dir) && false === @mkdir($dir, 0770, true) && false === is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s', $dir));
        }

        $lines = [];
        $senders = [];
        foreach ($this->keys->findAllActive() as $key) {
            $address = strtolower(trim((string) $key->account->email));
            if ('' !== $address) {
                $senders[$address][] = sprintf('smtp_%d', $key->id);
            }

            // Username is smtp_<id>; Dovecot hashes the presented secret.
            $lines[] = sprintf(
                'smtp_%d:{SHA256.HEX}%s:5000:5000::/nonexistent',
                $key->id,
                $key->keyHash,
            );
        }

        $senderLines = [];
        foreach ($senders as $address => $logins) {
            $senderLines[] = $address . ' ' . implode(',', $logins);
        }
        $this->write(self::SENDERS_PATH, $senderLines);
        $this->write(self::KEYS_PATH, $lines);
    }

    /**
     * @param list<string> $lines
     */
    private function write(string $path, array $lines): void
    {
        $tmp = $path . '.tmp';
        if (false === file_put_contents($tmp, implode("\n", $lines) . "\n")) {
            throw new \RuntimeException(sprintf('Could not write %s', $tmp));
        }
        // Readable by Dovecot's auth process whichever user it runs as. The
        // lines are SHA-256 hashes of 256-bit random secrets — not reversible.
        chmod($tmp, 0644);
        rename($tmp, $path);
    }
}
