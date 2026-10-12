<?php

declare(strict_types=1);

namespace App\Repository\System;

use App\Entity\System\MailLoginAddress;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * BearerMail: storage for MailLoginGuard.
 *
 * The hot paths (asked before every mail login, told after it) are single
 * SQL statements: no entity loading, and an atomic counter, because an
 * attacker's failed logins arrive in parallel.
 *
 * @extends ServiceEntityRepository<MailLoginAddress>
 */
final class MailLoginAddressRepository extends ServiceEntityRepository
{
    private const string FMT = 'Y-m-d H:i:s';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MailLoginAddress::class);
    }

    public function isBlocked(string $ip, DateTimeImmutable $now): bool
    {
        return false !== $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT 1 FROM mail_login_address
              WHERE ip = :ip AND trusted = false AND blocked_at IS NOT NULL
                AND (blocked_until IS NULL OR blocked_until > :now)',
            ['ip' => $ip, 'now' => $now->format(self::FMT)],
        );
    }

    /**
     * Counts one failed login. Failures older than $windowStart no longer
     * count: the window starts again from this one.
     *
     * @return array{failures:int, trusted:bool, blocked:bool}
     */
    public function recordFailure(string $ip, ?string $login, DateTimeImmutable $now, DateTimeImmutable $windowStart): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(
            'INSERT INTO mail_login_address
                    (ip, failures, window_started_at, last_failure_at, last_login, times_blocked, trusted, created_at)
             VALUES (:ip, 1, :now, :now, :login, 0, false, :now)
             ON CONFLICT (ip) DO UPDATE SET
                    failures = CASE WHEN mail_login_address.window_started_at IS NULL
                                      OR mail_login_address.window_started_at < :windowStart
                                    THEN 1 ELSE mail_login_address.failures + 1 END,
                    window_started_at = CASE WHEN mail_login_address.window_started_at IS NULL
                                               OR mail_login_address.window_started_at < :windowStart
                                             THEN :now ELSE mail_login_address.window_started_at END,
                    last_failure_at = :now,
                    last_login = :login
             RETURNING failures, trusted,
                       (blocked_at IS NOT NULL AND (blocked_until IS NULL OR blocked_until > :now)) AS blocked',
            [
                'ip'          => $ip,
                'login'       => null === $login ? null : mb_substr($login, 0, 255),
                'now'         => $now->format(self::FMT),
                'windowStart' => $windowStart->format(self::FMT),
            ],
        );

        return [
            'failures' => (int) ($row['failures'] ?? 0),
            'trusted'  => true === ($row['trusted'] ?? false) || 't' === ($row['trusted'] ?? null),
            'blocked'  => true === ($row['blocked'] ?? false) || 't' === ($row['blocked'] ?? null),
        ];
    }

    /** @param ?DateTimeImmutable $until null = until an admin unblocks it */
    public function block(string $ip, DateTimeImmutable $now, ?DateTimeImmutable $until): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE mail_login_address
                SET blocked_at = :now, blocked_until = :until, times_blocked = times_blocked + 1
              WHERE ip = :ip AND trusted = false',
            ['ip' => $ip, 'now' => $now->format(self::FMT), 'until' => $until?->format(self::FMT)],
        );
    }

    /** A correct password from here: its earlier typos are forgiven. */
    public function recordSuccess(string $ip): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE mail_login_address SET failures = 0, window_started_at = NULL
              WHERE ip = :ip AND failures > 0',
            ['ip' => $ip],
        );
    }

    /** Forgets addresses that are not trusted, not blocked now, and have been quiet since $before. */
    public function purgeQuiet(DateTimeImmutable $before): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM mail_login_address
              WHERE trusted = false
                AND (blocked_at IS NULL OR (blocked_until IS NOT NULL AND blocked_until < :before))
                AND (last_failure_at IS NULL OR last_failure_at < :before)',
            ['before' => $before->format(self::FMT)],
        );
    }

    /** @return list<MailLoginAddress> blocked right now, newest first */
    public function findBlocked(DateTimeImmutable $now, int $limit = 300): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.trusted = false')
            ->andWhere('a.blockedAt IS NOT NULL')
            ->andWhere('a.blockedUntil IS NULL OR a.blockedUntil > :now')
            ->setParameter('now', $now)
            ->orderBy('a.blockedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return list<MailLoginAddress> */
    public function findTrusted(): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.trusted = true')
            ->orderBy('a.ip', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<MailLoginAddress> addresses with failed logins lately that are not blocked */
    public function findFailing(DateTimeImmutable $since, DateTimeImmutable $now, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.failures > 0')
            ->andWhere('a.lastFailureAt >= :since')
            ->andWhere('a.blockedAt IS NULL OR (a.blockedUntil IS NOT NULL AND a.blockedUntil <= :now)')
            ->setParameter('since', $since)
            ->setParameter('now', $now)
            ->orderBy('a.lastFailureAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByIp(string $ip): ?MailLoginAddress
    {
        return $this->findOneBy(['ip' => $ip]);
    }
}
