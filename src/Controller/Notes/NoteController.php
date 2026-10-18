<?php

declare(strict_types=1);

namespace App\Controller\Notes;

use App\Controller\ChecksCsrf;
use App\Entity\Note\Note;
use App\Entity\User\User;
use App\Repository\Note\NoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Keep-style notes in the browser: the same notes the Android app keeps
 * through Note/get and Note/set, on the same `user_note` rows.
 *
 * Plain forms and redirects, no script: a wall of cards (pinned first), a
 * "take a note" form on top, and a page per note to edit its text or
 * checklist, colour, pin, archive and bin. The limits are Note/set's, so a
 * note saved here always reads back in the app and the other way round.
 */
#[Route('/notes', name: 'app_notes_')]
#[IsGranted('ROLE_USER')]
final class NoteController extends AbstractController
{
    use ChecksCsrf;

    private const array VIEWS = ['notes', 'archive', 'trash'];

    private const int MAX_BODY  = 50_000;
    private const int MAX_ITEMS = 300;
    private const int MAX_ITEM  = 1_000;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $view = (string) $request->query->get('view', 'notes');
        $view = \in_array($view, self::VIEWS, true) ? $view : 'notes';
        $q    = trim((string) $request->query->get('q', ''));

        $shown = array_values(array_filter(
            $this->notes->findForUser($this->user()),
            static fn (Note $note): bool => match ($view) {
                'trash'   => null !== $note->trashedAt,
                'archive' => null === $note->trashedAt && $note->archived,
                default   => null === $note->trashedAt && false === $note->archived,
            } && ('' === $q || self::matches($note, $q)),
        ));

        return $this->render('notes/list.html.twig', [
            'view'   => $view,
            'q'      => $q,
            'pinned' => array_values(array_filter($shown, static fn (Note $n): bool => 'notes' === $view && $n->pinned)),
            'others' => array_values(array_filter($shown, static fn (Note $n): bool => 'notes' !== $view || false === $n->pinned)),
            'colors' => Note::COLORS,
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, 'notes_create');

        $note      = new Note();
        $note->usr = $this->user();
        $this->applyForm($note, $request);

        if ('' === $note->title && '' === $note->body && [] === $note->items) {
            // An empty "take a note" box closed without typing: nothing to keep.
            return $this->redirectToRoute('app_notes_list', [], Response::HTTP_SEE_OTHER);
        }

        $this->em->persist($note);
        $this->em->flush();

        return $this->redirectToRoute('app_notes_list', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}', name: 'edit', methods: ['GET'])]
    public function edit(int $id): Response
    {
        return $this->render('notes/edit.html.twig', [
            'note'   => $this->ownNote($id),
            'colors' => Note::COLORS,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'update', methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);
        $this->applyForm($note, $request);
        $this->em->flush();

        return $this->back($note);
    }

    /** One-click actions from a card or the editor: pin, archive, bin, restore, delete, tick an item. */
    #[Route('/{id<\d+>}/{action<pin|archive|trash|restore|destroy|toggle>}', name: 'action', methods: ['POST'])]
    public function action(int $id, string $action, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        switch ($action) {
            case 'pin':
                $note->pinned = !$note->pinned;
                if ($note->pinned) {
                    $note->archived = false;
                }
                break;
            case 'archive':
                $note->archived = !$note->archived;
                if ($note->archived) {
                    $note->pinned = false;
                }
                break;
            case 'trash':
                $note->trashedAt ??= new DateTimeImmutable();
                $note->pinned = false;
                break;
            case 'restore':
                $note->trashedAt = null;
                break;
            case 'destroy':
                $this->em->remove($note);
                $this->em->flush();

                return $this->redirectToRoute('app_notes_list', ['view' => 'trash'], Response::HTTP_SEE_OTHER);
            case 'toggle':
                $index = $request->request->getInt('item', -1);
                $items = $note->items;
                if (isset($items[$index])) {
                    $items[$index]['done'] = !($items[$index]['done'] ?? false);
                    $note->items = array_values($items);
                }
                break;
        }

        $this->em->flush();

        return $this->back($note, (string) $request->request->get('return', ''));
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Title, colour, and either the text or the checklist, from the form.
     * A checklist arrives as one item per line; a line starting "[x]" is done.
     */
    private function applyForm(Note $note, Request $request): void
    {
        $note->title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $request->request->get('title', ''))), 0, 255);

        $color       = (string) $request->request->get('color', '');
        $note->color = \in_array($color, Note::COLORS, true) ? $color : null;

        $text = str_replace(["\r\n", "\r"], "\n", (string) $request->request->get('body', ''));
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        if ($request->request->getBoolean('checklist')) {
            $items = [];
            foreach (explode("\n", $text) as $line) {
                $done = 1 === preg_match('/^\s*\[[xX]\]\s*/', $line);
                $line = trim((string) preg_replace('/^\s*\[[ xX]?\]\s*/', '', $line));
                if ('' === $line) {
                    continue;
                }
                $items[] = ['text' => mb_substr($line, 0, self::MAX_ITEM), 'done' => $done];
            }
            $note->items = \array_slice($items, 0, self::MAX_ITEMS);
            $note->body  = '';
        } else {
            $note->body  = mb_substr(trim($text), 0, self::MAX_BODY);
            $note->items = [];
        }
    }

    private static function matches(Note $note, string $q): bool
    {
        $haystack = $note->title . "\n" . $note->body;
        foreach ($note->items as $item) {
            $haystack .= "\n" . ($item['text'] ?? '');
        }

        return false !== mb_stripos($haystack, $q);
    }

    private function back(Note $note, string $return = ''): Response
    {
        if ('edit' === $return) {
            return $this->redirectToRoute('app_notes_edit', ['id' => $note->id], Response::HTTP_SEE_OTHER);
        }

        $view = null !== $note->trashedAt ? 'trash' : ($note->archived ? 'archive' : 'notes');

        return $this->redirectToRoute('app_notes_list', 'notes' === $view ? [] : ['view' => $view], Response::HTTP_SEE_OTHER);
    }

    private function ownNote(int $id): Note
    {
        $note = $this->notes->find($id);

        if (null === $note || $note->usr->id !== $this->user()->id) {
            throw $this->createNotFoundException();
        }

        return $note;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
