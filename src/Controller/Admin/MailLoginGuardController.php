<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Repository\System\MailLoginAddressRepository;
use App\Service\Mail\MailLoginGuard;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BearerMail: Admin → Security → Mail login blocking.
 *
 * The settings (on/off, how many wrong passwords, within how long, blocked
 * for how long), the addresses blocked right now with Unblock and "Unblock
 * and always allow", the always-allowed list, and addresses that are failing
 * but not blocked yet.
 *
 * Every POST redirects back to the frame (Post/Redirect/Get), so Turbo
 * follows it and a refresh never repeats an action.
 */
#[Route('/admin/security/mail-logins', name: 'app_admin_mail_logins_')]
#[IsGranted('ROLE_ADMIN')]
final class MailLoginGuardController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly MailLoginGuard $guard,
        private readonly MailLoginAddressRepository $addresses,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $now = new DateTimeImmutable();

        return $this->render('admin/security/_mail_logins.html.twig', [
            'settings'  => $this->guard->settings(),
            'connected' => $this->guard->isConnected(),
            'blocked'   => $this->addresses->findBlocked($now),
            'trusted'   => $this->addresses->findTrusted(),
            'failing'   => $this->addresses->findFailing($now->modify('-24 hours'), $now),
            'now'       => $now,
            'saved'     => $request->query->getString('saved'),
            'error'     => $request->query->getString('error'),
        ]);
    }

    #[Route('/settings', name: 'settings', methods: ['POST'])]
    public function settings(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'admin_mail_logins_settings');

        $this->guard->saveSettings([
            'enabled'       => $request->request->getBoolean('enabled'),
            'maxFailures'   => $request->request->getInt('maxFailures', MailLoginGuard::DEFAULTS['maxFailures']),
            'windowMinutes' => $request->request->getInt('windowMinutes', MailLoginGuard::DEFAULTS['windowMinutes']),
            'blockHours'    => $request->request->getInt('blockHours', MailLoginGuard::DEFAULTS['blockHours']),
        ]);

        return $this->back('settings');
    }

    #[Route('/unblock', name: 'unblock', methods: ['POST'])]
    public function unblock(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'admin_mail_logins_unblock');

        $ip = (string) $request->request->get('ip', '');
        $this->guard->unblock($ip, $request->request->getBoolean('trust'));

        return $this->back($request->request->getBoolean('trust') ? 'trusted' : 'unblocked');
    }

    #[Route('/trust', name: 'trust', methods: ['POST'])]
    public function trust(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'admin_mail_logins_trust');

        $ip = trim((string) $request->request->get('ip', ''));
        $trusted = '0' !== (string) $request->request->get('trusted', '1');

        if (null === MailLoginGuard::normalize($ip)) {
            return $this->back('', 'bad_ip');
        }
        $this->guard->setTrusted($ip, $trusted);

        return $this->back($trusted ? 'trusted' : 'untrusted');
    }

    private function back(string $saved, string $error = ''): RedirectResponse
    {
        return $this->redirectToRoute('app_admin_mail_logins_index', array_filter(['saved' => $saved, 'error' => $error]), Response::HTTP_SEE_OTHER);
    }
}
