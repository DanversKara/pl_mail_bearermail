<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\User\User;
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
     * The user's account being flooded, with how many messages arrived in
     * the window; null when none is. The busiest, if several are.
     *
     * One query whatever the number of accounts: this runs on every inbox
     * visit and every navigation of the list.
     *
     * @return array{isFlood: bool, count: int, account: string}|null
     */
    public function busiestFlooded(User $user): ?array
    {
        $since = new \DateTimeImmutable(sprintf('-%d minutes', self::WINDOW_MINUTES));
        $found = $this->messages->busiestAccountSince($user, $since, self::THRESHOLD);

        return null === $found ? null : ['isFlood' => true, 'count' => $found['count'], 'account' => $found['email']];
    }
}
