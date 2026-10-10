<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * The outbound SMTP relay (e.g. Mailjet) as the plMail admin UI sees it.
 *
 * plMail hands outgoing mail to the local Postfix (mta:587); Postfix relays
 * it onward when a relay is configured. That configuration lives in
 * /etc/plmail-mail-config/relay.conf — a shared Docker volume the mta
 * container watches and applies without a restart (see
 * docker/mail/postfix/entrypoint.sh).
 *
 * The file is KEY="value" lines:
 *   RELAYHOST="[in-v3.mailjet.com]:587"
 *   MAIL_RELAY_USER="api-key"
 *   MAIL_RELAY_PASS="secret"
 *
 * An absent file (or an empty RELAYHOST) means direct delivery. Env vars
 * (MAIL_RELAYHOST etc.) override the file when non-empty — the entrypoint
 * decides that, not this class.
 *
 * The password sits in plaintext in a root-owned volume on the user's own
 * server — the same posture as the env-var configuration it replaces.
 */
final class RelayConfigWriter
{
    private const string CONF_PATH = '/etc/plmail-mail-config/relay.conf';

    /**
     * @return array{host: string, username: string, hasPassword: bool}
     */
    public function current(): array
    {
        $values = $this->read();

        return [
            'host'        => $values['RELAYHOST'] ?? '',
            'username'    => $values['MAIL_RELAY_USER'] ?? '',
            'hasPassword' => '' !== ($values['MAIL_RELAY_PASS'] ?? ''),
        ];
    }

    public function isConfigured(): bool
    {
        $values = $this->read();

        return '' !== ($values['RELAYHOST'] ?? '');
    }

    /**
     * @param array{host?: string, username?: string, password?: string|null} $data
     */
    public function save(array $data): void
    {
        $host     = trim((string) ($data['host'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $dir = \dirname(self::CONF_PATH);
        if (false === is_dir($dir) && false === mkdir($dir, 0777, true) && false === is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create %s', $dir));
        }

        // Empty host clears the relay (direct delivery). An empty password
        // keeps the stored one — the form never renders it back.
        if ('' === $host) {
            if (file_exists(self::CONF_PATH)) {
                unlink(self::CONF_PATH);
            }

            return;
        }

        $existing = $this->read();
        if ('' === $password) {
            $password = $existing['MAIL_RELAY_PASS'] ?? '';
        }

        // One value per line, wrapped in quotes and written verbatim: the
        // readers (read() below and the mta entrypoint) strip exactly the
        // outer pair. Escaping with backslashes, as this first did, meant a
        // secret containing " or \ reached Postfix with the backslashes in it.
        foreach ([$host, $username, $password] as $value) {
            if (1 === preg_match('/[\r\n\x00]/', $value)) {
                throw new \InvalidArgumentException('Relay settings cannot contain line breaks.');
            }
        }

        $lines = [
            sprintf('RELAYHOST="%s"', $host),
            sprintf('MAIL_RELAY_USER="%s"', $username),
            sprintf('MAIL_RELAY_PASS="%s"', $password),
        ];

        $tmp = self::CONF_PATH . '.tmp';
        if (false === file_put_contents($tmp, implode("\n", $lines) . "\n")) {
            throw new \RuntimeException(sprintf('Could not write %s', $tmp));
        }
        chmod($tmp, 0600);
        rename($tmp, self::CONF_PATH);
    }

    /**
     * @return array<string, string>
     */
    private function read(): array
    {
        if (false === is_readable(self::CONF_PATH)) {
            return [];
        }

        $values = [];
        foreach (file(self::CONF_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (false === str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            if (\strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            $values[trim($key)] = $value;
        }

        return $values;
    }
}
