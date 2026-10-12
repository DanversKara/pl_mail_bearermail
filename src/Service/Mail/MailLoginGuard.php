<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\System\MailLoginAddress;
use App\Entity\System\SecurityEvent;
use App\Repository\System\MailLoginAddressRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * BearerMail: blocks network addresses that keep failing to sign in to the
 * mail server.
 *
 * Dovecot asks this (through its auth-policy hook, see
 * MailAuthPolicyController) before every login — "may this address try?" —
 * and tells it afterwards whether the password was right. Too many wrong
 * passwords from one address within the window and that address is blocked
 * for a while: even the right password is refused from it, so a password
 * guesser gets nowhere.
 *
 * Only logins from the internet count: IMAP on the public port (993) from a
 * public address. The app's own connections inside Docker (port 143, and
 * Postfix's submission checks) and anything from a home or Docker network
 * are never counted or blocked, so this can never lock the web or phone app
 * out of the mailboxes.
 *
 * Settings live next to the other mail-server files on the shared volume,
 * so they survive rebuilds and are part of the mail config.
 */
final class MailLoginGuard
{
    public const string DIR = '/etc/plmail-mail-config';
    private const string SETTINGS = self::DIR . '/login_guard.json';
    private const string KEY = self::DIR . '/auth_policy_key';

    /** The port outside mail apps sign in on. Everything else is the app itself. */
    public const int PUBLIC_PORT = 993;

    public const array DEFAULTS = [
        'enabled'       => true,
        'maxFailures'   => 10,
        'windowMinutes' => 60,
        'blockHours'    => 24,
    ];

    public function __construct(
        private readonly MailLoginAddressRepository $addresses,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    // ── Dovecot's questions ─────────────────────────────────────────────────

    /** Before a login: false = refuse it without even checking the password. */
    public function mayTry(?string $ip, ?int $localPort): bool
    {
        $ip = self::normalize($ip);
        if (null === $ip || false === $this->counts($ip, $localPort) || false === $this->settings()['enabled']) {
            return true;
        }

        return false === $this->addresses->isBlocked($ip, new DateTimeImmutable());
    }

    /** After a login. $policyReject = it was refused by mayTry(), not by the password. */
    public function report(?string $ip, ?int $localPort, ?string $login, bool $success, bool $policyReject): void
    {
        $ip = self::normalize($ip);
        if (null === $ip || false === $this->counts($ip, $localPort) || true === $policyReject) {
            return;
        }

        if (true === $success) {
            $this->addresses->recordSuccess($ip);

            return;
        }

        $settings = $this->settings();
        $now = new DateTimeImmutable();
        $state = $this->addresses->recordFailure(
            $ip,
            $login,
            $now,
            $now->modify(sprintf('-%d minutes', $settings['windowMinutes'])),
        );

        if (true === $settings['enabled']
            && false === $state['trusted']
            && false === $state['blocked']
            && $state['failures'] >= $settings['maxFailures']
        ) {
            $until = $settings['blockHours'] > 0 ? $now->modify(sprintf('+%d hours', $settings['blockHours'])) : null;
            $this->addresses->block($ip, $now, $until);
            $this->logger->warning('Mail login: blocked {ip} after {n} failed logins', [
                'ip' => $ip, 'n' => $state['failures'], 'login' => $login,
            ]);
            $this->recordEvent($ip, sprintf('%d failed mail logins, last as %s', $state['failures'], $login ?? '?'));
        }

        // Now and then, forget addresses that tried once and went away.
        if (1 === random_int(1, 200)) {
            $this->addresses->purgeQuiet($now->modify('-30 days'));
        }
    }

    // ── Admin ───────────────────────────────────────────────────────────────

    public function unblock(string $ip, bool $trust): void
    {
        $address = $this->addresses->findOneByIp($ip);
        if (null === $address) {
            return;
        }
        $address->blockedAt = null;
        $address->blockedUntil = null;
        $address->failures = 0;
        $address->windowStartedAt = null;
        if (true === $trust) {
            $address->trusted = true;
        }
        $this->em->flush();
    }

    public function setTrusted(string $ip, bool $trusted): void
    {
        $ip = self::normalize($ip) ?? throw new \InvalidArgumentException('Not an IP address.');
        $address = $this->addresses->findOneByIp($ip);
        if (null === $address) {
            if (false === $trusted) {
                return;
            }
            $address = new MailLoginAddress();
            $address->ip = $ip;
            $this->em->persist($address);
        }
        $address->trusted = $trusted;
        if (true === $trusted) {
            $address->blockedAt = null;
            $address->blockedUntil = null;
            $address->failures = 0;
            $address->windowStartedAt = null;
        }
        $this->em->flush();
    }

    /**
     * Read fresh every time: in worker mode this service outlives the request
     * that changed the settings, and the file is tiny.
     *
     * @return array{enabled:bool, maxFailures:int, windowMinutes:int, blockHours:int}
     */
    public function settings(): array
    {
        $stored = [];
        if (is_file(self::SETTINGS)) {
            $decoded = json_decode((string) @file_get_contents(self::SETTINGS), true);
            $stored = is_array($decoded) ? $decoded : [];
        }

        return self::clean($stored + self::DEFAULTS);
    }

    /** @param array<string, mixed> $values */
    public function saveSettings(array $values): void
    {
        $settings = self::clean($values + self::DEFAULTS);
        if (false === is_dir(self::DIR)) {
            @mkdir(self::DIR, 0755, true);
        }
        $tmp = self::SETTINGS . '.tmp';
        if (false === @file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT))) {
            throw new \RuntimeException(sprintf('Could not write %s', self::SETTINGS));
        }
        rename($tmp, self::SETTINGS);
    }

    /** The secret Dovecot sends with each question. Made by the mailstore container on its first start. */
    public function policyKey(): ?string
    {
        if (false === is_file(self::KEY)) {
            return null;
        }
        $key = trim((string) @file_get_contents(self::KEY));

        return strlen($key) >= 32 ? $key : null;
    }

    /** Whether Dovecot has started asking: the key exists once the mailstore ran with this version. */
    public function isConnected(): bool
    {
        return null !== $this->policyKey();
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function counts(string $ip, ?int $localPort): bool
    {
        // Home and Docker networks are never counted: your own phone on the
        // Wi-Fi, a mail account set up with the server's public name and
        // looped back by the router, and Docker's own addresses all arrive
        // from private ranges. A password guesser on the internet never does.
        if (self::isPrivate($ip)) {
            return false;
        }

        // No port in the request: an older mailstore config. Count it rather
        // than silently doing nothing; the app's own logins always carry 143.
        return null === $localPort || self::PUBLIC_PORT === $localPort;
    }

    public static function isPrivate(string $ip): bool
    {
        return false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    public static function normalize(?string $ip): ?string
    {
        $ip = trim((string) $ip);
        if (str_starts_with(strtolower($ip), '::ffff:') && false !== filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }
        if (false === filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        // Never block the machine itself.
        if ('127.0.0.1' === $ip || '::1' === $ip) {
            return null;
        }

        return strtolower($ip);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{enabled:bool, maxFailures:int, windowMinutes:int, blockHours:int}
     */
    private static function clean(array $values): array
    {
        return [
            'enabled'       => (bool) $values['enabled'],
            'maxFailures'   => max(3, min(1000, (int) $values['maxFailures'])),
            'windowMinutes' => max(1, min(10080, (int) $values['windowMinutes'])),
            'blockHours'    => max(0, min(8760, (int) $values['blockHours'])),
        ];
    }

    private function recordEvent(string $ip, string $details): void
    {
        try {
            $this->em->persist(SecurityEvent::create(SecurityEvent::TYPE_MAIL_LOGIN_BLOCKED, null, $ip, $details));
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->notice('Mail login: could not record the security event', ['error' => $e->getMessage()]);
        }
    }
}
