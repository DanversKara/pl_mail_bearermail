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
}
