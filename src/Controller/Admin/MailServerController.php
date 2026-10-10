<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Controller\ChecksCsrf;
use App\Form\Admin\RelayConfigType;
use App\Repository\Mail\DmarcReportRepository;
use App\Service\Mail\DmarcReportParser;
use App\Service\Mail\DnsChecker;
use App\Service\Mail\RelayConfigWriter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin → Mail server: the outbound SMTP relay (e.g. Mailjet).
 *
 * plMail hands outgoing mail to the local Postfix; Postfix relays it onward
 * when a relay is configured here. The form writes
 * /etc/plmail-mail-config/relay.conf (a shared Docker volume), which the mta
 * container watches and applies within seconds — no shell, no rebuild.
 *
 * One action for GET and POST, as the rest of the admin area does it. The
 * password field is write-only: it never renders the stored value, so an
 * empty submission keeps what is on file, and clearing the host field removes
 * the relay entirely (direct delivery).
 */
#[Route('/admin/mail-server', name: 'app_admin_mail_server_')]
#[IsGranted('ROLE_ADMIN')]
final class MailServerController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly RelayConfigWriter $writer,
        private readonly DmarcReportRepository $dmarcReports,
        private readonly DmarcReportParser $dmarcParser,
        private readonly EntityManagerInterface $em,
        private readonly DnsChecker $dns,
        // From .env.mail.local via compose's env_file. Read through %env()%,
        // not $_ENV: in the container these are real environment variables,
        // and PHP leaves $_ENV empty unless variables_order includes E.
        #[Autowire('%env(default::MAIL_DOMAIN)%')]
        private readonly ?string $mailDomain = null,
        #[Autowire('%env(default::SERVER_IP)%')]
        private readonly ?string $serverIp = null,
        #[Autowire('%env(default::MAIL_DKIM_SELECTOR)%')]
        private readonly ?string $dkimSelector = null,
    ) {}

    #[Route('', name: 'settings', methods: ['GET', 'POST'])]
    public function settings(Request $request): Response
    {
        $current = $this->writer->current();

        $form = $this->createForm(RelayConfigType::class, [
            'host'     => $current['host'],
            'username' => $current['username'],
            // password deliberately not bound: write-only.
        ], [
            'action' => $this->generateUrl('app_admin_mail_server_settings'),
        ]);
        $form->handleRequest($request);

        $saved = false;

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            try {
                $this->writer->save([
                    'host'     => $form->get('host')->getData(),
                    'username' => $form->get('username')->getData(),
                    'password' => $form->get('password')->getData(),
                ]);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $form->addError(new \Symfony\Component\Form\FormError($e->getMessage()));

                return $this->render('admin/mail-server/_frame.html.twig', [
                    'form'         => $form,
                    'saved'        => false,
                    'isConfigured' => $this->writer->isConfigured(),
                    'hasPassword'  => $current['hasPassword'],
                    'dmarcReports' => $this->dmarcReports->findRecent(20),
                    'dnsResults'   => null,
                    'mailDomain'   => $this->mailDomain,
                ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            }

            // Re-created rather than reused: the password field is write-only
            // and must not re-render what was pasted.
            $current = $this->writer->current();
            $form    = $this->createForm(RelayConfigType::class, [
                'host'     => $current['host'],
                'username' => $current['username'],
            ], [
                'action' => $this->generateUrl('app_admin_mail_server_settings'),
            ]);
            $saved = true;
        }

        // DNS records check, on demand via ?check_dns=1.
        $dnsResults = null;
        if ('1' === $request->query->get('check_dns') && '' !== (string) $this->mailDomain) {
            $dnsResults = $this->dns->check(
                (string) $this->mailDomain,
                (string) $this->serverIp,
                '' !== (string) $this->dkimSelector ? (string) $this->dkimSelector : 'mail',
            );
        }

        return $this->render('admin/mail-server/_frame.html.twig', [
            'form'         => $form,
            'saved'        => $saved,
            'isConfigured' => $this->writer->isConfigured(),
            'hasPassword'  => $current['hasPassword'],
            'dmarcReports' => $this->dmarcReports->findRecent(20),
            'dnsResults'   => $dnsResults,
            'mailDomain'   => $this->mailDomain,
        ]);
    }

    /**
     * Upload a DMARC aggregate report (.zip, .gz, .xml) for parsing.
     */
    #[Route('/dmarc/upload', name: 'dmarc_upload', methods: ['POST'])]
    public function dmarcUpload(Request $request): Response
    {
        $this->assertCsrf($request, 'admin_mail_server');

        /** @var UploadedFile|null $file */
        $file = $request->files->get('dmarc_report');

        if (null === $file || false === $file->isValid()) {
            $this->addFlash('error', 'admin.mail_server.dmarc.upload_invalid');

            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'mail-server']);
        }

        try {
            $report = $this->dmarcParser->parseFile($file->getPathname(), $file->getClientOriginalName());
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_admin_dashboard', ['section' => 'mail-server']);
        }

        $this->em->persist($report);
        $this->em->flush();

        $this->addFlash('success', 'admin.mail_server.dmarc.uploaded');

        return $this->redirectToRoute('app_admin_dashboard', ['section' => 'mail-server']);
    }
}
