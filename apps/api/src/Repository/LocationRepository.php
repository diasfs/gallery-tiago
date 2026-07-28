<?php

namespace App\Repository;

use App\Entity\Location;
use App\Service\SearchText;
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
            $qb->andWhere(
                'LOWER(UNACCENT(COALESCE(l.name, \'\'))) LIKE :query
                OR LOWER(UNACCENT(COALESCE(l.city, \'\'))) LIKE :query
                OR LOWER(UNACCENT(COALESCE(l.country, \'\'))) LIKE :query'
            )->setParameter('query', SearchText::likePattern($query));
        }

        return $qb->getQuery()->getResult();
    }
}
