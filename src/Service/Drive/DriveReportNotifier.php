<?php

declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\Drive\DriveShareReport;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Mail\MailSenderRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * BearerMail: the two emails a Drive link report sends.
 *
 *  - To the person who reported it, if they left an address: "we got it".
 *  - To every administrator: what was reported, why, and where to act.
 *
 * Sent from an administrator's own mailbox — this server has no separate
 * system mailer — preferring one on MAIL_DOMAIN (support@…). If no admin has
 * a mailbox, nothing is sent and the report still waits in Admin → Drive.
 */
final readonly class DriveReportNotifier
{
    public function __construct(
        private UserRepository $users,
        private MailSenderRegistry $senders,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        #[Autowire('%env(default:app.brand_name_default:APP_BRAND_NAME)%')]
        private string $brand,
        #[Autowire('%env(default::MAIL_DOMAIN)%')]
        private ?string $mailDomain = null,
    ) {
    }

    public function reportFiled(DriveShareReport $report): void
    {
        $admins = $this->users->findAdmins();
        $account = $this->senderAccount($admins);

        if (null === $account) {
            $this->logger->info('Drive report: no administrator mailbox to send notifications from');

            return;
        }

        $reason = ucfirst($report->reason);

        if (null !== $report->reporterEmail) {
            $this->send($account, $report->reporterEmail, sprintf('We received your report — %s', $this->brand), implode("\n", [
                'Hello,',
                '',
                sprintf('Thank you for reporting the shared file "%s" (%s).', $report->fileName, $reason),
                '',
                'Our team will review it as quickly as possible and take action if it breaks our rules.',
                'You do not need to do anything else, and we will contact you here if we need more information.',
                '',
                sprintf('— %s', $this->brand),
            ]));
        }

        $adminLink = $this->urls->generate('app_admin_dashboard', ['section' => 'drive'], UrlGeneratorInterface::ABSOLUTE_URL);

        foreach ($admins as $admin) {
            $to = $admin->getUserIdentifier();
            if (false === filter_var($to, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $this->send($account, $to, sprintf('[%s] Drive link reported: %s', $this->brand, $report->fileName), implode("\n", [
                sprintf('A shared Drive file was reported: %s', $report->fileName),
                '',
                sprintf('Reason: %s', $reason),
                sprintf('Details: %s', $report->details ?? '—'),
                sprintf('Reported by: %s', $report->reporterEmail ?? 'anonymous'),
                sprintf('From address: %s', $report->reporterIp ?? '—'),
                '',
                'Review it and revoke the link, switch off the owner\'s Drive, or dismiss it:',
                $adminLink,
            ]));
        }
    }

    /** @param list<User> $admins */
    private function senderAccount(array $admins): ?Account
    {
        $domain = strtolower(trim((string) $this->mailDomain));
        $fallback = null;

        foreach ($admins as $admin) {
            foreach ($admin->accounts as $account) {
                $address = strtolower((string) $account->displayAddress);
                if (false === filter_var($address, FILTER_VALIDATE_EMAIL) || false === $account->isActive) {
                    continue;
                }
                if ('' !== $domain && str_ends_with($address, '@' . $domain)) {
                    return $account;
                }
                $fallback ??= $account;
            }
        }

        return $fallback;
    }

    private function send(Account $account, string $to, string $subject, string $text): void
    {
        try {
            $email = new Email()
                ->from(new Address((string) $account->displayAddress, $this->brand))
                ->to($to)
                ->subject($subject)
                ->text($text);

            $this->senders->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            $this->logger->warning('Drive report: could not send a notification', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
