<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SynstituteInstance;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SynstituteInstance>
 */
class SynstituteInstanceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SynstituteInstance::class);
    }

    public function findActiveByIdentifier(string $identifier): ?SynstituteInstance
    {
        return $this->findOneBy([
            'identifier' => $identifier,
            'isActive' => true,
        ]);
    }
}
