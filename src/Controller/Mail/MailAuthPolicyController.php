<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Service\Mail\MailLoginGuard;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * BearerMail: Dovecot's auth-policy hook (auth_policy_server_url).
 *
 * Dovecot calls this from inside Docker, at http://php/internal/mail-auth-policy:
 *
 *   ?command=allow   before a login — {"status": -1} refuses it, 0 lets it try
 *   ?command=report  after it, with "success" and "policy_reject" added
 *
 * The body is the attributes set in the mailstore's auth-policy.conf
 * (login, remote, lport, protocol). Every request carries the shared key
 * from /etc/plmail-mail-config/auth_policy_key in X-Mail-Policy-Key; without
 * it the answer is 403, which Dovecot treats as "no opinion" and lets the
 * login through (auth_policy_reject_on_fail = no) — so a broken key or a
 * stopped app never locks anyone out, it only switches the blocking off.
 *
 * Outside the security firewall (security.yaml: mail_policy): there is no
 * user here, only the key.
 */
final class MailAuthPolicyController extends AbstractController
{
    public function __construct(
        private readonly MailLoginGuard $guard,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/internal/mail-auth-policy', name: 'app_mail_auth_policy', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $key = $this->guard->policyKey();
        $sent = (string) $request->headers->get('X-Mail-Policy-Key', '');
        if (null === $key || '' === $sent || false === hash_equals($key, $sent)) {
            return new JsonResponse(['status' => 0, 'msg' => 'forbidden'], 403);
        }

        $body = json_decode($request->getContent(), true);
        $body = is_array($body) ? $body : [];

        $ip = isset($body['remote']) ? (string) $body['remote'] : null;
        $port = isset($body['lport']) && is_numeric($body['lport']) ? (int) $body['lport'] : null;
        $login = isset($body['login']) && '' !== (string) $body['login'] ? (string) $body['login'] : null;

        try {
            if ('report' === $request->query->getString('command')) {
                $this->guard->report(
                    $ip,
                    $port,
                    $login,
                    true === ($body['success'] ?? false),
                    true === ($body['policy_reject'] ?? false),
                );

                return new JsonResponse(['status' => 0]);
            }

            if (false === $this->guard->mayTry($ip, $port)) {
                return new JsonResponse(['status' => -1, 'msg' => 'Too many failed logins from this address.']);
            }
        } catch (\Throwable $e) {
            // Never turn a database hiccup into a lockout.
            $this->logger->error('Mail login guard failed', ['error' => $e->getMessage()]);
        }

        return new JsonResponse(['status' => 0]);
    }
}
