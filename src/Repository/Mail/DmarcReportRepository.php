<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\DmarcReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DmarcReport>
 */
class DmarcReportRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DmarcReport::class);
    }

    /**
     * @return list<DmarcReport> Newest first.
     */
    public function findRecent(int $limit = 50): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.dateTo', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
