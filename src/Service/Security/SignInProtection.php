<?php

declare(strict_types=1);

namespace App\Service\Security;

use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * BearerMail: Admin → Security → Sign-in protection.
 *
 *   - Two-factor sign-in required: off, for administrators, or for everyone.
 *     Somebody it applies to who has not set it up is sent to Settings →
 *     Security on the web until they have (SignInProtectionSubscriber), and
 *     may not switch it off again.
 *   - Admin only from these networks: /admin answers 403 to any other
 *     address. Saving refuses a list that leaves out the address doing the
 *     saving, so it cannot lock out the person who turns it on.
 *
 * Way back in if the home address changes: ADMIN_NETWORK_LOCK=off in
 * .env.mail.local, or `bin/console app:security:admin-unlock`.
 *
 * Kept as a small JSON file on the secrets volume (php and worker share it,
 * it survives rebuilds) rather than a table: it is read on every request and
 * has three values.
 */
final class SignInProtection
{
    public const array TWO_FACTOR = ['off', 'admins', 'everyone'];

    public const array DEFAULTS = [
        'twoFactor'     => 'off',
        'adminLock'     => false,
        'adminNetworks' => [],
    ];

    /** @var array{twoFactor: string, adminLock: bool, adminNetworks: list<string>}|null */
    private ?array $cache = null;

    public function __construct(
        #[Autowire('%app.sign_in_protection_file%')]
        private readonly string $file,
        #[Autowire('%app.admin_network_lock%')]
        private readonly string $adminLockOverride = '',
    ) {
    }

    /** @return array{twoFactor: string, adminLock: bool, adminNetworks: list<string>} */
    public function settings(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $stored = [];
        if (is_file($this->file)) {
            $decoded = json_decode((string) @file_get_contents($this->file), true);
            $stored  = is_array($decoded) ? $decoded : [];
        }

        return $this->cache = self::clean($stored + self::DEFAULTS);
    }

    /** @param array<string, mixed> $values */
    public function save(array $values): void
    {
        $settings = self::clean($values + $this->settings());
        $dir      = \dirname($this->file);
        if (false === is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $tmp = $this->file . '.tmp';
        if (false === @file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT))) {
            throw new \RuntimeException(sprintf('Could not write %s', $this->file));
        }
        @chmod($tmp, 0640);
        rename($tmp, $this->file);
        $this->cache = $settings;
    }

    public function requiresTwoFactor(User $user): bool
    {
        return match ($this->settings()['twoFactor']) {
            'everyone' => true,
            'admins'   => \in_array('ROLE_ADMIN', $user->getRoles(), true),
            default    => false,
        };
    }

    /** Whether the lock is on — and not switched off by ADMIN_NETWORK_LOCK=off. */
    public function adminLockActive(): bool
    {
        return true === $this->settings()['adminLock']
            && [] !== $this->settings()['adminNetworks']
            && false === \in_array(strtolower(trim($this->adminLockOverride)), ['off', '0', 'false', 'no'], true);
    }

    public function adminAllowedFrom(?string $ip): bool
    {
        if (false === $this->adminLockActive()) {
            return true;
        }

        return self::inNetworks($ip, $this->settings()['adminNetworks']);
    }

    /** @param list<string> $networks */
    public static function inNetworks(?string $ip, array $networks): bool
    {
        return null !== $ip && '' !== $ip && [] !== $networks && IpUtils::checkIp($ip, $networks);
    }

    /**
     * One address or range (CIDR) per line, or separated by commas or spaces.
     * Null when any entry is not one.
     *
     * @return list<string>|null
     */
    public static function parseNetworks(string $text): ?array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', trim($text)) ?: [] as $entry) {
            if ('' === $entry) {
                continue;
            }
            if (null === self::normalizeNetwork($entry)) {
                return null;
            }
            $out[] = self::normalizeNetwork($entry);
        }

        return array_values(array_unique($out));
    }

    public static function normalizeNetwork(string $entry): ?string
    {
        $entry = trim($entry);
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        if (false === filter_var($address, FILTER_VALIDATE_IP)) {
            return null;
        }
        if (null === $bits) {
            return $address;
        }
        $max = str_contains($address, ':') ? 128 : 32;
        if (1 !== preg_match('/^\d{1,3}$/', $bits) || (int) $bits > $max) {
            return null;
        }

        return $address . '/' . (int) $bits;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array{twoFactor: string, adminLock: bool, adminNetworks: list<string>}
     */
    private static function clean(array $values): array
    {
        $twoFactor = \in_array($values['twoFactor'] ?? null, self::TWO_FACTOR, true) ? $values['twoFactor'] : 'off';
        $networks  = [];
        foreach ((array) ($values['adminNetworks'] ?? []) as $entry) {
            $normal = \is_string($entry) ? self::normalizeNetwork($entry) : null;
            if (null !== $normal) {
                $networks[] = $normal;
            }
        }

        return [
            'twoFactor'     => $twoFactor,
            'adminLock'     => true === ($values['adminLock'] ?? false),
            'adminNetworks' => array_values(array_unique($networks)),
        ];
    }
}
