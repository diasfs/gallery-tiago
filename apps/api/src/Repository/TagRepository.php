<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Enum\AlbumVisibility;
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

    /**
     * Tags attached to at least one photo in a public album.
     *
     * @return Tag[]
     */
    public function searchPublic(?string $query, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere(
                'EXISTS (
                    SELECT 1 FROM App\Entity\Photo p
                    JOIN p.album a
                    JOIN p.tags pt
                    WHERE pt = t AND a.visibility = :visibility
                )'
            )
            ->setParameter('visibility', AlbumVisibility::Public)
            ->orderBy('t.name', 'ASC')
            ->setMaxResults($limit);

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(t.name) LIKE :query OR LOWER(t.slug) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower(trim($query)).'%');
        }

        return $qb->getQuery()->getResult();
    }
}
