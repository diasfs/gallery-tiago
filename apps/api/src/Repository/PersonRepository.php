<?php

namespace App\Repository;

use App\Entity\Person;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Person>
 */
class PersonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Person::class);
    }

    /** @return Person[] */
    public function findUnnamed(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.isNamed = false')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return Person[] */
    public function searchNamed(?string $query): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.isNamed = true')
            ->orderBy('p.name', 'ASC')
            ->setMaxResults(20);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(p.name) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
