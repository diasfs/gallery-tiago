<?php

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tag>
 */
class TagRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tag::class);
    }

    /** @return Tag[] */
    public function search(?string $query): array
    {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.name', 'ASC')
            ->setMaxResults(50);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(t.name) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
