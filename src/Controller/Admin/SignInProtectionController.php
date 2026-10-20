<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Service\Security\SignInProtection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BearerMail: Admin → Security → Sign-in protection. Two-factor required
 * (off / administrators / everyone) and the Admin network lock. See
 * App\Service\Security\SignInProtection.
 */
#[Route('/admin/security/sign-in', name: 'app_admin_sign_in_')]
#[IsGranted('ROLE_ADMIN')]
final class SignInProtectionController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly SignInProtection $protection,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $settings = $this->protection->settings();
        $ip       = $request->getClientIp();

        return $this->render('admin/security/_sign_in.html.twig', [
            'settings'   => $settings,
            'ip'         => $ip,
            'ipIncluded' => SignInProtection::inNetworks($ip, $settings['adminNetworks']),
            'overridden' => $settings['adminLock'] && false === $this->protection->adminLockActive(),
            'networks'   => $request->query->has('networks') ? $request->query->getString('networks') : implode("\n", $settings['adminNetworks']),
            'saved'      => $request->query->getString('saved'),
            'error'      => $request->query->getString('error'),
        ]);
    }

    #[Route('', name: 'save', methods: ['POST'])]
    public function save(Request $request): RedirectResponse
    {
        $this->assertCsrf($request, 'admin_sign_in_protection');

        $text     = (string) $request->request->get('adminNetworks', '');
        $networks = SignInProtection::parseNetworks($text);
        $lock     = $request->request->getBoolean('adminLock');

        if (null === $networks) {
            return $this->back('', 'bad_network', $text);
        }
        if ($lock && [] === $networks) {
            return $this->back('', 'no_networks', $text);
        }
        // Never save a lock that would shut out the person saving it.
        if ($lock && false === SignInProtection::inNetworks($request->getClientIp(), $networks)) {
            return $this->back('', 'would_lock_out', $text);
        }

        $this->protection->save([
            'twoFactor'     => (string) $request->request->get('twoFactor', 'off'),
            'adminLock'     => $lock,
            'adminNetworks' => $networks,
        ]);

        return $this->back('saved');
    }

    private function back(string $saved, string $error = '', ?string $networks = null): RedirectResponse
    {
        return $this->redirectToRoute(
            'app_admin_sign_in_index',
            array_filter(['saved' => $saved, 'error' => $error]) + (null !== $networks ? ['networks' => $networks] : []),
            Response::HTTP_SEE_OTHER,
        );
    }
}
