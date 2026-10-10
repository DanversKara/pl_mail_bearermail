<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\SmtpKey;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SmtpKey>
 */
class SmtpKeyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SmtpKey::class);
    }

    /**
     * All active keys for a user, newest first.
     *
     * @return list<SmtpKey>
     */
    public function findActiveForUser(User $user): array
    {
        return $this->createQueryBuilder('k')
            ->addSelect('a')
            ->join('k.account', 'a')
            ->where('k.usr = :user')
            ->andWhere('k.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('k.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All active keys, for syncing to the mta container.
     *
     * @return list<SmtpKey>
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('k')
            ->addSelect('a', 'u')
            ->join('k.account', 'a')
            ->join('k.usr', 'u')
            ->where('k.revokedAt IS NULL')
            ->getQuery()
            ->getResult();
    }
}
