<?php

declare(strict_types=1);

namespace App\Repository\System;

use App\Entity\System\SecurityEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SecurityEvent>
 */
class SecurityEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityEvent::class);
    }

    /**
     * @return list<SecurityEvent> Newest first.
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->createQueryBuilder('e')
            ->leftJoin('e.usr', 'u')
            ->addSelect('u')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * IPs with many failed logins recently — the suspicious ones.
     *
     * @return list<array{ip: string, attempts: int}>
     */
    public function suspiciousIps(int $minutes = 60, int $threshold = 5): array
    {
        $since = new \DateTimeImmutable(sprintf('-%d minutes', $minutes));

        $rows = $this->createQueryBuilder('e')
            ->select('e.ipAddress AS ip, COUNT(e.id) AS attempts')
            ->where('e.type = :type')
            ->andWhere('e.createdAt >= :since')
            ->andWhere('e.ipAddress IS NOT NULL')
            ->groupBy('e.ipAddress')
            ->having('COUNT(e.id) >= :threshold')
            ->setParameter('type', SecurityEvent::TYPE_LOGIN_FAILURE)
            ->setParameter('since', $since)
            ->setParameter('threshold', $threshold)
            ->orderBy('attempts', 'DESC')
            ->getQuery()
            ->getResult();

        return array_map(fn($r) => ['ip' => $r['ip'], 'attempts' => (int) $r['attempts']], $rows);
    }
}
