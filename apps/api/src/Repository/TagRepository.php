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
            $qb->andWhere('LOWER(t.name) LIKE :query OR LOWER(t.slug) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<array{tag: Tag, photoCount: int}>
     */
    public function searchWithPhotoCount(?string $query): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t AS tag', 'COUNT(p.id) AS photoCount')
            ->leftJoin('t.photos', 'p')
            ->groupBy('t.id')
            ->orderBy('COUNT(p.id)', 'DESC')
            ->addOrderBy('t.name', 'ASC')
            ->setMaxResults(200);

        if (null !== $query && '' !== $query) {
            $qb->andWhere('LOWER(t.name) LIKE :query OR LOWER(t.slug) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        $rows = $qb->getQuery()->getResult();
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'tag' => $row['tag'],
                'photoCount' => (int) $row['photoCount'],
            ];
        }

        return $out;
    }
}
