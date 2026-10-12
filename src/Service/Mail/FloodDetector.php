<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\MessageRepository;

/**
 * Detects mail floods: sudden bursts of incoming mail used to bury a
 * security alert (password reset, 2FA change, etc.) under noise.
 *
 * A flood is N or more messages arriving within M minutes. When one is
 * detected, the mailbox shows a banner warning the user — and pointing them
 * at the security-relevant messages (login codes, password resets) that the
 * flood may be trying to hide.
 */
final readonly class FloodDetector
{
    /** Messages within the window that count as a flood. */
    private const int THRESHOLD = 25;

    /** The window, in minutes. */
    private const int WINDOW_MINUTES = 15;

    public function __construct(
        private MessageRepository $messages,
    ) {}

    /**
     * The first of these accounts that is being flooded, in the order given,
     * with how many messages arrived in the window; null when none is.
     *
     * One query for all of them: this runs on every inbox visit, and a count
     * per account would cost one query each.
     *
     * @param list<Account> $accounts
     *
     * @return array{isFlood: bool, count: int, account: string}|null
     */
    public function firstFlooded(array $accounts): ?array
    {
        $since  = new \DateTimeImmutable(sprintf('-%d minutes', self::WINDOW_MINUTES));
        $counts = $this->messages->countPerAccountSince(
            array_values(array_filter(array_map(static fn (Account $a): ?int => $a->id, $accounts))),
            $since,
        );

        foreach ($accounts as $account) {
            $count = null === $account->id ? 0 : ($counts[$account->id] ?? 0);

            if ($count >= self::THRESHOLD) {
                return ['isFlood' => true, 'count' => $count, 'account' => (string) $account->email];
            }
        }

        return null;
    }
}
