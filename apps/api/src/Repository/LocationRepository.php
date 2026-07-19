<?php

namespace App\Repository;

use App\Entity\Location;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Location>
 */
class LocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Location::class);
    }

    /** @return Location[] */
    public function search(?string $query): array
    {
        $qb = $this->createQueryBuilder('l')
            ->orderBy('l.name', 'ASC')
            ->setMaxResults(20);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(l.name) LIKE :query OR LOWER(l.city) LIKE :query OR LOWER(l.country) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
