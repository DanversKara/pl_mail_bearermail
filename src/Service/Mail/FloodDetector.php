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
     * @return array{isFlood: bool, count: int}|null Null when not a flood.
     */
    public function check(Account $account): ?array
    {
        $since = new \DateTimeImmutable(sprintf('-%d minutes', self::WINDOW_MINUTES));

        $count = $this->messages->countForAccountSince($account->id, $since);

        if ($count < self::THRESHOLD) {
            return null;
        }

        return [
            'isFlood' => true,
            'count'   => $count,
        ];
    }
}
