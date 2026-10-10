<?php

declare(strict_types=1);

namespace App\Controller\Drive;

use App\Entity\Drive\DriveFile;
use App\Entity\Drive\DriveFolder;
use App\Entity\Drive\DriveShare;
use App\Entity\User\User;
use App\Repository\Drive\DriveFileRepository;
use App\Repository\Drive\DriveFolderRepository;
use App\Repository\Drive\DriveShareRepository;
use App\Service\Drive\DriveStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Drive for the phone app: plain JSON over the JMAP firewall.
 *
 * Under /jmap/ on purpose. That path is the stateless `jmap` firewall, so the
 * app authenticates exactly as it does for mail — the app password or JWT it
 * already holds, as a Bearer header — and there is no session, cookie or CSRF
 * token involved. The web Drive (DriveController) stays on the session
 * firewall; both share the same tables and DriveStorage.
 *
 * Uploads are the raw request body, like JMAP's own /jmap/upload, with the
 * file name and folder in the query string — simpler for a phone than
 * multipart, and the same size limit as JMAP (50 MB).
 *
 * Discovered through the JMAP Session's `urn:bearermail:params:drive`
 * capability, whose `apiUrl` is this prefix.
 *
 * Errors are {"type": "...", "detail": "..."} with a 4xx status, the same
 * shape as JmapUploadController's.
 */
#[Route('/jmap/drive', name: 'jmap_drive_')]
final class DriveApiController extends AbstractController
{
    private const int MAX_UPLOAD = 50_000_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DriveFileRepository $files,
        private readonly DriveFolderRepository $folders,
        private readonly DriveShareRepository $shares,
        private readonly DriveStorage $storage,
        #[Autowire('%env(default:drive.quota_mb_default:int:DRIVE_QUOTA_MB)%')]
        private readonly int $quotaMb,
    ) {}

    // ── Browsing ─────────────────────────────────────────────────────────────

    /** One folder (or the root): its folders, its files, where it sits, storage used. */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $folder = null;
        $folderId = $request->query->getInt('folder');
        if ($folderId > 0) {
            $folder = $this->folders->findOneForUser($user, $folderId);
            if (null === $folder) {
                return $this->problem('notFound', 'No such folder.', Response::HTTP_NOT_FOUND);
            }
        }

        $shareCounts = $this->shares->activeCountsForUser($user);

        return new JsonResponse([
            'folder'      => null !== $folder ? $this->folderJson($folder) : null,
            'breadcrumbs' => null !== $folder
                ? array_map($this->folderJson(...), $this->folders->breadcrumbs($folder))
                : [],
            'folders'     => array_map($this->folderJson(...), $this->folders->findForUser($user, $folder)),
            'files'       => array_map(
                fn(DriveFile $f) => $this->fileJson($f, $shareCounts[$f->id] ?? 0),
                $this->files->findForUser($user, $folder),
            ),
            'usedBytes'   => $this->files->totalSizeForUser($user),
            'quotaBytes'  => $this->quotaMb > 0 ? $this->quotaMb * 1024 * 1024 : null,
        ]);
    }

    /** Every file, newest first, with its folder's name — for pickers (compose, calendar). */
    #[Route('/all', name: 'all', methods: ['GET'])]
    public function all(#[CurrentUser] User $user): JsonResponse
    {
        $shareCounts = $this->shares->activeCountsForUser($user);

        return new JsonResponse([
            'files' => array_map(
                fn(DriveFile $f) => $this->fileJson($f, $shareCounts[$f->id] ?? 0) + ['folderName' => $f->folder?->name],
                $this->files->findAllForUser($user),
            ),
        ]);
    }

    // ── Upload / download ────────────────────────────────────────────────────

    #[Route('/upload', name: 'upload', methods: ['POST'])]
    public function upload(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $name = trim((string) $request->query->get('name', ''));
        if ('' === $name || 1 === preg_match('/[\x00-\x1f]/', $name)) {
            return $this->problem('invalidArguments', 'Give the file a name (?name=…).', Response::HTTP_BAD_REQUEST);
        }

        $folder = null;
        $folderId = $request->query->getInt('folder');
        if ($folderId > 0) {
            $folder = $this->folders->findOneForUser($user, $folderId);
            if (null === $folder) {
                return $this->problem('notFound', 'No such folder.', Response::HTTP_NOT_FOUND);
            }
        }

        $content = $request->getContent();
        $size = \strlen($content);

        if (0 === $size) {
            return $this->problem('invalidArguments', 'The request body is empty.', Response::HTTP_BAD_REQUEST);
        }
        if ($size > self::MAX_UPLOAD) {
            return $this->problem('tooLarge', sprintf('Uploads are limited to %d bytes.', self::MAX_UPLOAD), Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }
        if ($this->quotaMb > 0 && $this->files->totalSizeForUser($user) + $size > $this->quotaMb * 1024 * 1024) {
            return $this->problem('overQuota', 'Not enough space left in your Drive for that file.', Response::HTTP_INSUFFICIENT_STORAGE);
        }

        $mime = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if ('' === $mime || 1 !== preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#', $mime)) {
            $mime = 'application/octet-stream';
        }

        $file = DriveFile::create($user, $name, substr($mime, 0, 100), $size, $folder);
        $this->em->persist($file);
        $this->em->flush();

        try {
            $this->storage->storeContent($content, $file);
        } catch (\Throwable) {
            $this->em->remove($file);
            $this->em->flush();

            return $this->problem('serverFail', 'The file could not be saved.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($this->fileJson($file, 0), Response::HTTP_CREATED);
    }

    #[Route('/file/{id<\d+>}/download', name: 'download', methods: ['GET'])]
    public function download(int $id, #[CurrentUser] User $user): Response
    {
        $file = $this->files->findOneForUser($user, $id);
        if (null === $file || false === $this->storage->exists($file)) {
            return $this->problem('notFound', 'No such file.', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($this->storage->path($file));
        $response->headers->set('Content-Type', $file->mimeType);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $file->filename,
            str_replace(['/', '\\', '%'], '_', preg_replace('/[^\x20-\x7e]/', '_', $file->filename) ?? 'download'),
        );

        return $response;
    }

    // ── Folders ──────────────────────────────────────────────────────────────

    #[Route('/folder', name: 'folder_create', methods: ['POST'])]
    public function folderCreate(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $body = $this->body($request);
        $name = trim((string) ($body['name'] ?? ''));
        if ('' === $name) {
            return $this->problem('invalidArguments', 'Give the folder a name.', Response::HTTP_BAD_REQUEST);
        }

        $parent = null;
        if (isset($body['parentId']) && (int) $body['parentId'] > 0) {
            $parent = $this->folders->findOneForUser($user, (int) $body['parentId']);
            if (null === $parent) {
                return $this->problem('notFound', 'No such parent folder.', Response::HTTP_NOT_FOUND);
            }
        }

        $folder = DriveFolder::create($user, $name, $parent);
        $this->em->persist($folder);
        $this->em->flush();

        return new JsonResponse($this->folderJson($folder), Response::HTTP_CREATED);
    }

    #[Route('/folder/{id<\d+>}', name: 'folder_rename', methods: ['PATCH'])]
    public function folderRename(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $folder = $this->folders->findOneForUser($user, $id);
        if (null === $folder) {
            return $this->problem('notFound', 'No such folder.', Response::HTTP_NOT_FOUND);
        }

        $name = trim((string) ($this->body($request)['name'] ?? ''));
        if ('' === $name) {
            return $this->problem('invalidArguments', 'Give the folder a name.', Response::HTTP_BAD_REQUEST);
        }

        $folder->rename($name);
        $this->em->flush();

        return new JsonResponse($this->folderJson($folder));
    }

    /** Deletes the folder, everything below it, and the bytes on disk — as the web Drive does. */
    #[Route('/folder/{id<\d+>}', name: 'folder_delete', methods: ['DELETE'])]
    public function folderDelete(int $id, #[CurrentUser] User $user): Response
    {
        $folder = $this->folders->findOneForUser($user, $id);
        if (null === $folder) {
            return $this->problem('notFound', 'No such folder.', Response::HTTP_NOT_FOUND);
        }

        foreach ($this->files->findInFolders($user, $this->folders->subtreeIds($folder)) as $file) {
            $this->storage->delete($file);
            $this->em->remove($file);
        }
        $this->em->remove($folder);
        $this->em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    // ── Files ────────────────────────────────────────────────────────────────

    #[Route('/file/{id<\d+>}', name: 'file_rename', methods: ['PATCH'])]
    public function fileRename(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $this->files->findOneForUser($user, $id);
        if (null === $file) {
            return $this->problem('notFound', 'No such file.', Response::HTTP_NOT_FOUND);
        }

        $body = $this->body($request);
        $name = trim((string) ($body['name'] ?? ''));
        if ('' !== $name) {
            $file->rename($name);
        }

        if (\array_key_exists('folderId', $body)) {
            $target = null;
            if (null !== $body['folderId'] && (int) $body['folderId'] > 0) {
                $target = $this->folders->findOneForUser($user, (int) $body['folderId']);
                if (null === $target) {
                    return $this->problem('notFound', 'No such folder.', Response::HTTP_NOT_FOUND);
                }
            }
            $file->moveTo($target);
        }

        $this->em->flush();

        return new JsonResponse($this->fileJson($file, \count($this->shares->findActiveForFile($file))));
    }

    #[Route('/file/{id<\d+>}', name: 'file_delete', methods: ['DELETE'])]
    public function fileDelete(int $id, #[CurrentUser] User $user): Response
    {
        $file = $this->files->findOneForUser($user, $id);
        if (null === $file) {
            return $this->problem('notFound', 'No such file.', Response::HTTP_NOT_FOUND);
        }

        $this->storage->delete($file);
        $this->em->remove($file);
        $this->em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    // ── Share links ──────────────────────────────────────────────────────────

    #[Route('/file/{id<\d+>}/shares', name: 'shares', methods: ['GET'])]
    public function shares(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $file = $this->files->findOneForUser($user, $id);
        if (null === $file) {
            return $this->problem('notFound', 'No such file.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'shares' => array_map($this->shareJson(...), $this->shares->findActiveForFile($file)),
        ]);
    }

    /**
     * A new public link. Body (all optional): {"password": "…", "expires": "YYYY-MM-DD"}.
     * The answer carries `url` (a landing page) and `downloadUrl` (direct, for
     * a link without a password) — the latter is what goes into an email.
     */
    #[Route('/file/{id<\d+>}/shares', name: 'share_create', methods: ['POST'])]
    public function shareCreate(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $file = $this->files->findOneForUser($user, $id);
        if (null === $file) {
            return $this->problem('notFound', 'No such file.', Response::HTTP_NOT_FOUND);
        }

        $body = $this->body($request);

        $expiresAt = null;
        $expires = trim((string) ($body['expires'] ?? ''));
        if ('' !== $expires) {
            $expiresAt = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires) ?: null;
            $expiresAt = $expiresAt?->setTime(23, 59, 59);
            if (null === $expiresAt || $expiresAt < new \DateTimeImmutable()) {
                return $this->problem('invalidArguments', 'Pick an expiry date in the future (YYYY-MM-DD).', Response::HTTP_BAD_REQUEST);
            }
        }

        $password = (string) ($body['password'] ?? '');
        $share = DriveShare::create($file, '' !== $password ? $password : null, $expiresAt);
        $this->em->persist($share);
        $this->em->flush();

        return new JsonResponse($this->shareJson($share), Response::HTTP_CREATED);
    }

    #[Route('/share/{id<\d+>}', name: 'share_delete', methods: ['DELETE'])]
    public function shareDelete(int $id, #[CurrentUser] User $user): Response
    {
        $share = $this->shares->find($id);
        if (null === $share || $share->file->usr->id !== $user->id) {
            return $this->problem('notFound', 'No such link.', Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($share);
        $this->em->flush();

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    // ── Shapes ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function folderJson(DriveFolder $folder): array
    {
        return [
            'id'       => $folder->id,
            'name'     => $folder->name,
            'parentId' => $folder->parent?->id,
        ];
    }

    /** @return array<string, mixed> */
    private function fileJson(DriveFile $file, int $links): array
    {
        return [
            'id'        => $file->id,
            'name'      => $file->filename,
            'type'      => $file->mimeType,
            'size'      => $file->sizeBytes,
            'folderId'  => $file->folder?->id,
            'updatedAt' => $file->updatedAt->format(\DateTimeInterface::ATOM),
            'shareCount'=> $links,
        ];
    }

    /** @return array<string, mixed> */
    private function shareJson(DriveShare $share): array
    {
        return [
            'id'            => $share->id,
            'url'           => $this->generateUrl('app_share_access', ['token' => $share->token], UrlGeneratorInterface::ABSOLUTE_URL),
            'downloadUrl'   => null === $share->passwordHash
                ? $this->generateUrl('app_share_download', ['token' => $share->token], UrlGeneratorInterface::ABSOLUTE_URL)
                : null,
            'protected'     => null !== $share->passwordHash,
            'expiresAt'     => $share->expiresAt?->format(\DateTimeInterface::ATOM),
            'downloadCount' => $share->downloadCount,
            'createdAt'     => $share->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        try {
            $data = json_decode($request->getContent() ?: '{}', true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }

    private function problem(string $type, string $detail, int $status): JsonResponse
    {
        return new JsonResponse(['type' => $type, 'detail' => $detail], $status);
    }
}
