<?php

namespace App\Repository;

use App\Entity\Tag;
use App\Enum\AlbumVisibility;
use App\Enum\TagListSort;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
    public function searchWithPhotoCount(?string $query, int $limit = 200): array
    {
        return $this->searchPaginatedWithPhotoCount($query, 1, $limit)['items'];
    }

    /**
     * @return array{items: list<array{tag: Tag, photoCount: int}>, total: int}
     */
    public function searchPaginatedWithPhotoCount(
        ?string $query,
        int $page,
        int $perPage,
        TagListSort $sort = TagListSort::Recent,
    ): array {
        $base = $this->createQueryBuilder('t');

        if (null !== $query && '' !== $query) {
            $base->andWhere('LOWER(t.name) LIKE :query OR LOWER(t.slug) LIKE :query')
                ->setParameter('query', '%'.mb_strtolower($query).'%');
        }

        $total = (int) (clone $base)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $itemsQb = (clone $base)
            ->select(
                't AS tag',
                'COUNT(p.id) AS photoCount',
                'MAX(p.createdAt) AS lastPhotoAt',
                'CASE WHEN MAX(p.createdAt) IS NULL THEN 0 ELSE 1 END AS HIDDEN recentPresence',
            )
            ->leftJoin('t.photos', 'p')
            ->groupBy('t.id');

        $this->applyListSort($itemsQb, $sort);

        $rows = $itemsQb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'tag' => $row['tag'],
                'photoCount' => (int) $row['photoCount'],
            ];
        }

        return ['items' => $items, 'total' => $total];
    }

    private function applyListSort(QueryBuilder $qb, TagListSort $sort): void
    {
        match ($sort) {
            TagListSort::Name => $qb->orderBy('t.name', 'ASC')->addOrderBy('t.id', 'ASC'),
            TagListSort::Slug => $qb->orderBy('t.slug', 'ASC')->addOrderBy('t.id', 'ASC'),
            TagListSort::Recent => $qb
                ->orderBy('recentPresence', 'DESC')
                ->addOrderBy('lastPhotoAt', 'DESC')
                ->addOrderBy('t.name', 'ASC')
                ->addOrderBy('t.id', 'ASC'),
        };
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

    /**
     * Full public tag index: every tag with ≥1 photo in a public album,
     * ordered by name, with a count of those public photos.
     *
     * @return list<array{tag: Tag, photoCount: int}>
     */
    public function listPublicWithPhotoCount(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t AS tag', 'COUNT(p.id) AS photoCount')
            ->join('t.photos', 'p')
            ->join('p.album', 'a')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public)
            ->groupBy('t.id')
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();

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
