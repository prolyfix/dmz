<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AvailableSlot;
use App\Entity\SynstituteInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AvailableSlot>
 */
class AvailableSlotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvailableSlot::class);
    }

    /** @return AvailableSlot[] */
    public function findBookedNotExportedForInstance(SynstituteInstance $instance, int $limit = 500): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.synstituteInstance = :instance')
            ->andWhere('s.bookedAt IS NOT NULL')
            ->andWhere('s.exportedAt IS NULL')
            ->setParameter('instance', $instance)
            ->orderBy('s.bookedAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return AvailableSlot[] */
    public function findBookedForInstance(SynstituteInstance $instance, int $limit = 500): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.synstituteInstance = :instance')
            ->andWhere('s.bookedAt IS NOT NULL')
            ->setParameter('instance', $instance)
            ->orderBy('s.slotDate', 'ASC')
            ->addOrderBy('s.startAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneByInstanceAndSlotUid(SynstituteInstance $instance, string $slotUid): ?AvailableSlot
    {
        return $this->findOneBy([
            'synstituteInstance' => $instance,
            'slotUid' => $slotUid,
        ]);
    }

    /** @return AvailableSlot[] */
    public function findAvailableForInstance(SynstituteInstance $instance): array
    {
        $now = new \DateTimeImmutable('now');

        return $this->createQueryBuilder('s')
            ->andWhere('s.synstituteInstance = :instance')
            ->andWhere('s.bookedAt IS NULL')
            ->andWhere('(s.slotDate > :today OR (s.slotDate = :today AND s.startAt >= :currentTime))')
            ->setParameter('instance', $instance)
            ->setParameter('today', $now, Types::DATE_IMMUTABLE)
            ->setParameter('currentTime', $now, Types::TIME_IMMUTABLE)
            ->orderBy('s.slotDate', 'ASC')
            ->addOrderBy('s.startAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
