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

    /**
     * @param 'all'|'named'|'unnamed' $scope
     *
     * @return Person[]
     */
    public function search(string $scope = 'named', ?string $query = null, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.avatarFace', 'af')
            ->addSelect('af')
            ->setMaxResults($limit);

        if ('named' === $scope) {
            $qb->andWhere('p.isNamed = true')->orderBy('p.name', 'ASC');
        } elseif ('unnamed' === $scope) {
            $qb->andWhere('p.isNamed = false')->orderBy('p.id', 'ASC');
        } else {
            $qb->orderBy('p.isNamed', 'DESC')
                ->addOrderBy('p.name', 'ASC')
                ->addOrderBy('p.id', 'ASC');
        }

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(p.name) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /** @return Person[] */
    public function searchNamed(?string $query): array
    {
        return $this->search('named', $query, 20);
    }
}
