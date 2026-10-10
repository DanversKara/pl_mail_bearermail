<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\SmtpKey;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\SmtpKeyRepository;
use App\Service\Mail\SmtpKeySync;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manage SMTP keys from settings.
 *
 * An SMTP key lets a script send mail through the local Postfix without the
 * mailbox password. The secret is shown exactly once at creation; the mta
 * picks up new keys within seconds via the shared volume.
 */
#[Route('/settings/smtp-keys', name: 'app_smtp_key_')]
#[IsGranted('ROLE_USER')]
final class SmtpKeyController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmtpKeyRepository $keyRepository,
        private readonly AccountRepository $accountRepository,
        private readonly SmtpKeySync $sync,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): Response
    {
        return $this->renderFrame();
    }

    #[Route('/create', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $user = $this->user();

        $this->assertCsrf($request, 'smtp_key_create');

        $name = trim((string) $request->request->get('name', ''));
        $account = $this->accountRepository->find((int) $request->request->get('account_id', 0));

        if ('' === $name) {
            return $this->renderFrame(error: 'smtp_key.name_required');
        }
        if (null === $account || $account->usr?->id !== $user->id) {
            return $this->renderFrame(error: 'smtp_key.account_invalid');
        }

        ['key' => $key, 'secret' => $secret] = SmtpKey::create($user, $account, $name);

        $this->em->persist($key);
        $this->em->flush();

        // Push to the mail server so it accepts the key within seconds.
        $synced = $this->trySync();

        return $this->renderFrame(created: [
            'key'      => $key,
            'secret'   => $secret,
            'username' => sprintf('smtp_%d', $key->id),
        ], synced: $synced);
    }

    #[Route('/{id<\d+>}/revoke', name: 'revoke', methods: ['POST'])]
    public function revoke(Request $request, int $id): Response
    {
        $key = $this->keyRepository->find($id);
        if (null === $key || $key->usr->id !== $this->user()->id) {
            throw $this->createNotFoundException();
        }

        $this->assertCsrf($request, 'smtp_key_revoke_' . $key->id);

        $key->revoke();
        $this->em->flush();

        return $this->renderFrame(synced: $this->trySync());
    }

    /**
     * @param array{key: SmtpKey, secret: string, username: string}|null $created
     */
    private function renderFrame(?array $created = null, ?string $error = null, bool $synced = true): Response
    {
        $user = $this->user();

        return $this->render('settings/smtp-keys/_frame.html.twig', [
            'keys'     => $this->keyRepository->findActiveForUser($user),
            'accounts' => $this->accountRepository->findBy(['usr' => $user]),
            'created'  => $created,
            'error'    => $error,
            'synced'   => $synced,
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The shared volume only exists when the mail server profile runs. Without
     * it the key is saved but no server will accept it; say so instead of a 500.
     */
    private function trySync(): bool
    {
        try {
            $this->sync->sync();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
