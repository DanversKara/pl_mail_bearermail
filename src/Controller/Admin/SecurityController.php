<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\System\SecurityEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Security: recent security events and suspicious IPs.
 *
 * Events are logged by SecurityEventSubscriber (logins, failures, etc.).
 * The suspicious-IP list surfaces brute-force patterns: many failed logins
 * from one address in the last hour.
 */
#[Route('/admin/security', name: 'app_admin_security_')]
#[IsGranted('ROLE_ADMIN')]
final class SecurityController extends AbstractController
{
    public function __construct(
        private readonly SecurityEventRepository $events,
    ) {}

    #[Route('', name: 'overview', methods: ['GET'])]
    public function overview(): Response
    {
        return $this->render('admin/security/_frame.html.twig', [
            'events'        => $this->events->findRecent(100),
            'suspiciousIps' => $this->events->suspiciousIps(),
        ]);
    }
}
