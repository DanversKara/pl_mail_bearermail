<?php

declare(strict_types=1);

namespace App\Repository\Note;

use App\Entity\Note\Note;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Note>
 */
class NoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Note::class);
    }

    /**
     * Every note of one user, pinned first and then newest-changed first — the order a notes wall
     * is drawn in, so a client that sorts nothing still shows the right thing.
     *
     * @return list<Note>
     */
    public function findForUser(UserInterface $user): array
    {
        return $this->findBy(['usr' => $user], ['pinned' => 'DESC', 'updatedAt' => 'DESC', 'id' => 'DESC']);
    }

    /**
     * Notes (not in the bin) that link to one thing — a contact's or an event's
     * "Notes" section. An event link may name one occurrence of a series
     * ("12_20261020T090000Z", the JMAP occurrence id); it counts for the series.
     *
     * @return list<Note>
     */
    public function findLinkedTo(UserInterface $user, string $type, string $id): array
    {
        return array_values(array_filter(
            $this->findForUser($user),
            static function (Note $note) use ($type, $id): bool {
                if (null !== $note->trashedAt) {
                    return false;
                }
                foreach ($note->links as $link) {
                    $linkId = (string) ($link['id'] ?? '');
                    if (($link['type'] ?? '') === $type && ($linkId === $id || ('event' === $type && str_starts_with($linkId, $id . '_')))) {
                        return true;
                    }
                }

                return false;
            },
        ));
    }
}
