<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\System\SecurityEvent;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Logs security-relevant events for the admin Security page.
 *
 * Successful and failed logins are the core: a burst of failures from one IP
 * is the signature of a brute-force attempt, and the repository surfaces
 * those as "suspicious IPs".
 *
 * Successes are recorded for the interactive `main` firewall only. The JMAP
 * and CalDAV firewalls are stateless: they authenticate EVERY request, so
 * logging their successes wrote a row per sync poll — thousands a day for one
 * phone, burying the logins anyone would want to see. Failures are kept from
 * every firewall; a wrong app password is exactly what this page is for.
 */
final readonly class SecurityEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private RequestStack $requestStack,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }

        $user = $event->getUser();
        $this->log(
            SecurityEvent::TYPE_LOGIN_SUCCESS,
            $user instanceof User ? $user : null,
        );
    }

    public function onLoginFailure(LoginFailureEvent $event): void
    {
        // The username they tried — useful when it's not a real account.
        // LoginFailureEvent has no getUsername(); it lives on the passport's
        // UserBadge, which is absent when the request never got that far.
        $username = null;
        $badge = $event->getPassport()?->getBadge(UserBadge::class);
        if ($badge instanceof UserBadge) {
            $username = mb_substr($badge->getUserIdentifier(), 0, 200);
        }

        $this->log(
            SecurityEvent::TYPE_LOGIN_FAILURE,
            null,
            null !== $username && '' !== $username ? sprintf('Attempted username: %s', $username) : null,
        );
    }

    private function log(string $type, ?User $user, ?string $details = null): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $ip = $request?->getClientIp();

        $event = SecurityEvent::create($type, $user, $ip, $details);

        // Never let bookkeeping break a login: if the entity manager is closed
        // or the table is missing (migration not yet run), sign in anyway.
        try {
            $this->em->persist($event);
            $this->em->flush();
        } catch (\Throwable) {
        }
    }
}
