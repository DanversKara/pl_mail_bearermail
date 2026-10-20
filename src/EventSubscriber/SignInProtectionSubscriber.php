<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User\User;
use App\Service\Security\SignInProtection;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * BearerMail: enforces Admin → Security → Sign-in protection on the web.
 *
 * **Admin network lock.** Anything under /admin from an address outside the
 * saved list answers 403 and says which address the server saw, so the
 * owner knows what to add.
 *
 * **Two-factor required.** A signed-in web session whose user must have it
 * and has not set it up reaches Settings → Security (and signing out) only;
 * every other page redirects there with a note. Only the `main` firewall:
 * app passwords (phone, mail apps, CalDAV) are minted from a signed-in web
 * session, so a stolen password alone still cannot make one once 2FA is on.
 */
final readonly class SignInProtectionSubscriber implements EventSubscriberInterface
{
    /** Route-name prefixes still reachable while 2FA is being set up. */
    private const array SETUP_ROUTES = [
        'app_two_factor_',
        'app_settings_index',
        'app_settings_password_',
        'app_logout',
        'app_2fa_',
        '_',
    ];

    public function __construct(
        private SignInProtection $protection,
        private Security $security,
        private RequestStack $requestStack,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    /** @return array<string, array{string, int}> */
    public static function getSubscribedEvents(): array
    {
        // After the firewall (8), so a signed-out visitor still gets the login
        // page and the token is known.
        return [RequestEvent::class => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (str_starts_with($request->getPathInfo(), '/admin') && false === $this->protection->adminAllowedFrom($request->getClientIp())) {
            $event->setResponse($this->adminRefused($request));

            return;
        }

        if ('main' !== $this->security->getFirewallConfig($request)?->getName()) {
            return;
        }

        $token = $this->security->getToken();
        $user  = $token?->getUser();

        if ($token instanceof TwoFactorTokenInterface || false === $user instanceof User) {
            return;
        }

        if (true === $user->isTotpAuthenticationEnabled() || false === $this->protection->requiresTwoFactor($user)) {
            return;
        }

        $route = $request->attributes->get('_route');
        if (\is_string($route)) {
            foreach (self::SETUP_ROUTES as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    return;
                }
            }
        }

        $message = $this->translator->trans('sign_in_protection.two_factor_needed');

        // Background fetches, frames and form posts: refuse plainly. A redirect
        // inside a Turbo frame would leave it spinning (see DemoGuardSubscriber).
        $frame = (string) $request->headers->get('Turbo-Frame', '');
        if (false === $request->isMethod('GET') || '' !== $frame || false === \in_array('text/html', $request->getAcceptableContentTypes(), true)) {
            $event->setResponse(new Response($message, Response::HTTP_FORBIDDEN, ['Content-Type' => 'text/plain; charset=UTF-8']));

            return;
        }

        $session = $this->requestStack->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('info', $message);
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('app_settings_index', ['section' => 'security'])));
    }

    private function adminRefused(Request $request): Response
    {
        $frame = (string) $request->headers->get('Turbo-Frame', '');
        $ip    = $request->getClientIp() ?? '?';

        return new Response($this->twig->render(
            '' !== $frame ? 'admin/security/_network_refused_frame.html.twig' : 'admin/security/network_refused.html.twig',
            ['ip' => $ip, 'frame' => $frame],
        ), Response::HTTP_FORBIDDEN);
    }
}
