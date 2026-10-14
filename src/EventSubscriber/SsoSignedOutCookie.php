<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * BearerMail: remembers for a few minutes that this browser just signed out.
 *
 * With "go straight to the provider" on, the login page forwards to the
 * provider — and the provider still has its own session, so signing out of
 * BearerMail would sign the person straight back in. While this cookie is
 * there the login page shows the button and the form instead. Signing in
 * again clears it.
 */
final class SsoSignedOutCookie
{
    public const string NAME = 'bm_signed_out';

    private const int SECONDS = 600;

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $event->getResponse()?->headers->setCookie(
            Cookie::create(self::NAME, '1', time() + self::SECONDS, '/', null, null, true, false, Cookie::SAMESITE_LAX),
        );
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLogin(LoginSuccessEvent $event): void
    {
        if (true === $event->getRequest()->cookies->has(self::NAME)) {
            $event->getResponse()?->headers->clearCookie(self::NAME, '/', null, false, true, Cookie::SAMESITE_LAX);
        }
    }
}
