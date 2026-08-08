<?php

namespace App\Repository;

use App\Entity\Album;
use App\Enum\AlbumVisibility;
use App\Service\SearchText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Album>
 */
class AlbumRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Album::class);
    }

    /** @return Album[] */
    public function findPublicRoots(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.parent IS NULL')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: Album[], total: int}
     */
    public function findPublicRootsPaginated(int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('a')
            ->andWhere('a.parent IS NULL')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public);

        $total = (int) (clone $base)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return Album[] */
    public function findPublicRecent(int $limit): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public);
        $this->orderByPublicRecency($qb);

        return $qb
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: Album[], total: int}
     */
    public function findPublicMostViewedPaginated(
        int $page,
        int $perPage,
        bool $excludeRootAlbums = false,
    ): array {
        $base = $this->createQueryBuilder('a')
            ->andWhere('a.visibility IN (:visibilities)')
            ->andWhere('a.viewCount > 0')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);

        if ($excludeRootAlbums) {
            $base->andWhere('a.parent IS NOT NULL');
        }

        $total = (int) (clone $base)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('a.viewCount', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Public/unlisted albums whose date (takenAtEnd, takenAt, or createdAt) falls on
     * the given month/day in years before $beforeYear.
     *
     * @return array{items: Album[], total: int}
     */
    public function findPublicOnThisDayPaginated(
        int $month,
        int $day,
        int $beforeYear,
        int $page,
        int $perPage,
    ): array {
        $conn = $this->getEntityManager()->getConnection();
        $params = ['month' => $month, 'day' => $day, 'year' => $beforeYear];
        $types = [
            'month' => ParameterType::INTEGER,
            'day' => ParameterType::INTEGER,
            'year' => ParameterType::INTEGER,
        ];

        $where = <<<'SQL'
            a.visibility IN ('public', 'unlisted')
            AND EXTRACT(MONTH FROM COALESCE(a.taken_at_end, a.taken_at, a.created_at)) = :month
            AND EXTRACT(DAY FROM COALESCE(a.taken_at_end, a.taken_at, a.created_at)) = :day
            AND EXTRACT(YEAR FROM COALESCE(a.taken_at_end, a.taken_at, a.created_at)) < :year
        SQL;

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(a.id)::int FROM album a WHERE {$where}",
            $params,
            $types,
        );

        $offset = max(0, ($page - 1) * $perPage);
        $ids = $conn->fetchFirstColumn(
            <<<SQL
                SELECT a.id::text
                FROM album a
                WHERE {$where}
                ORDER BY COALESCE(a.taken_at_end, a.taken_at, a.created_at) DESC
                LIMIT :limit OFFSET :offset
            SQL,
            [...$params, 'limit' => $perPage, 'offset' => $offset],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => $this->loadAlbumsOrdered(array_map(static fn (mixed $id): string => (string) $id, $ids)),
            'total' => $total,
        ];
    }

    /**
     * Public albums (any depth) whose location has latitude and longitude.
     *
     * @return Album[]
     */
    public function findPublicWithCoordinates(): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.location', 'loc')
            ->addSelect('loc')
            ->andWhere('a.visibility = :visibility')
            ->andWhere('loc.latitude IS NOT NULL')
            ->andWhere('loc.longitude IS NOT NULL')
            ->setParameter('visibility', AlbumVisibility::Public)
            ->orderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneBySlug(string $slug): ?Album
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Atomically increments and returns the new view_count.
     * Safe under concurrent detail-page loads.
     */
    public function incrementViewCount(Uuid $id): int
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'UPDATE album SET view_count = view_count + 1 WHERE id = :id RETURNING view_count',
            ['id' => $id->toRfc4122()],
        );

        if (false === $result || null === $result) {
            throw new \RuntimeException(\sprintf('Album %s not found for view increment.', $id->toRfc4122()));
        }

        return (int) $result;
    }

    public function findVisibleBySlug(string $slug): ?Album
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.slug = :slug')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('slug', $slug)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Album[] */
    public function findVisibleChildren(Album $parent): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.parent = :parent')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('parent', $parent)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);
        $this->orderByAlbumDate($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{items: Album[], total: int}
     */
    public function findVisibleChildrenPaginated(Album $parent, int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('a')
            ->andWhere('a.parent = :parent')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('parent', $parent)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);

        $total = (int) (clone $base)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $itemsQb = clone $base;
        $this->orderByAlbumDate($itemsQb);
        $items = $itemsQb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** Sub-album lists: album date (takenAtEnd or takenAt) DESC; undated last. */
    private function orderByAlbumDate(QueryBuilder $qb): void
    {
        $qb
            ->addSelect('CASE WHEN COALESCE(a.takenAtEnd, a.takenAt) IS NULL THEN 0 ELSE 1 END AS HIDDEN hasDate')
            ->addSelect('COALESCE(a.takenAtEnd, a.takenAt) AS HIDDEN albumDate')
            ->orderBy('hasDate', 'DESC')
            ->addOrderBy('albumDate', 'DESC');
    }

    /**
     * Native albums (no legacyId) first by createdAt; imported by legacyId DESC
     * (old gallery id_album DESC). Used for public recent albums only.
     */
    private function orderByPublicRecency(QueryBuilder $qb): void
    {
        $qb
            ->addSelect('CASE WHEN a.legacyId IS NULL THEN 1 ELSE 0 END AS HIDDEN legacyNull')
            ->orderBy('legacyNull', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.legacyId', 'DESC')
            ->addOrderBy('a.title', 'ASC');
    }

    /** @return Album[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<string> */
    public function findDescendantIds(Album $album): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<'SQL'
            WITH RECURSIVE descendants AS (
                SELECT id FROM album WHERE parent_id = :id
                UNION ALL
                SELECT a.id FROM album a
                INNER JOIN descendants d ON a.parent_id = d.id
            )
            SELECT id::text FROM descendants
            SQL,
            ['id' => (string) $album->getId()],
        );

        return array_map(static fn (mixed $id): string => (string) $id, $rows);
    }

    /**
     * @param list<string> $excludeIds
     *
     * @return array{items: Album[], total: int}
     */
    public function findParentOptionsPaginated(int $page, int $perPage, ?string $q, array $excludeIds = []): array
    {
        $qb = $this->createQueryBuilder('a');

        if (null !== $q && '' !== trim($q)) {
            $qb->andWhere('(LOWER(UNACCENT(a.title)) LIKE :q OR LOWER(UNACCENT(a.slug)) LIKE :q)')
                ->setParameter('q', SearchText::likePattern($q));
        }

        if ([] !== $excludeIds) {
            $qb->andWhere('a.id NOT IN (:excludeIds)')
                ->setParameter(
                    'excludeIds',
                    array_map(static fn (string $id): Uuid => Uuid::fromString($id), $excludeIds),
                );
        }

        $total = (int) (clone $qb)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return Album[] */
    public function findRootsOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.parent IS NULL')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Lists root albums by default. When `q` is set, searches title/slug across
     * the whole tree (including sub-albums) so nested collections are findable.
     *
     * @param array{
     *   visibility?: string,
     *   q?: string,
     *   from?: string,
     *   to?: string,
     *   location?: string
     * } $filters
     *
     * @return array{items: Album[], total: int}
     */
    public function findRootsPaginated(int $page, int $perPage, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a');

        $hasQuery = isset($filters['q']) && \is_string($filters['q']) && '' !== trim($filters['q']);
        if (!$hasQuery) {
            $qb->andWhere('a.parent IS NULL');
        }

        if (isset($filters['visibility']) && \is_string($filters['visibility']) && '' !== $filters['visibility']) {
            $visibility = AlbumVisibility::tryFrom($filters['visibility']);
            if (null !== $visibility) {
                $qb->andWhere('a.visibility = :visibility')
                    ->setParameter('visibility', $visibility);
            }
        }

        if ($hasQuery) {
            $qb->andWhere('(LOWER(UNACCENT(a.title)) LIKE :q OR LOWER(UNACCENT(a.slug)) LIKE :q)')
                ->setParameter('q', SearchText::likePattern($filters['q']));
        }

        if (isset($filters['from']) && \is_string($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
            $qb->andWhere('a.takenAt IS NOT NULL')
                ->andWhere('COALESCE(a.takenAtEnd, a.takenAt) >= :from')
                ->setParameter('from', new \DateTimeImmutable($filters['from'].'T00:00:00Z'));
        }

        if (isset($filters['to']) && \is_string($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
            $qb->andWhere('a.takenAt IS NOT NULL')
                ->andWhere('a.takenAt <= :to')
                ->setParameter('to', new \DateTimeImmutable($filters['to'].'T23:59:59Z'));
        }

        if (isset($filters['location']) && \is_string($filters['location']) && '' !== trim($filters['location'])) {
            $qb->leftJoin('a.location', 'loc')
                ->andWhere(
                    'LOWER(UNACCENT(COALESCE(loc.name, \'\'))) LIKE :location
                    OR LOWER(UNACCENT(COALESCE(loc.city, \'\'))) LIKE :location
                    OR LOWER(UNACCENT(COALESCE(loc.country, \'\'))) LIKE :location'
                )
                ->setParameter('location', SearchText::likePattern($filters['location']));
        }

        $total = (int) (clone $qb)
            ->select('COUNT(DISTINCT a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /** @return Album[] */
    public function findChildrenOrdered(Album $parent): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.parent = :parent')
            ->setParameter('parent', $parent);
        $this->orderByAlbumDate($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return array{items: Album[], total: int}
     */
    public function findChildrenPaginated(Album $parent, int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('a')
            ->andWhere('a.parent = :parent')
            ->setParameter('parent', $parent);

        $total = (int) (clone $base)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $itemsQb = clone $base;
        $this->orderByAlbumDate($itemsQb);
        $items = $itemsQb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Photo counts for every album (avoids hydrating Photo entities on list).
     *
     * @return array<string, int> album uuid => count
     */
    public function countPhotosGroupedByAlbum(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT album_id, COUNT(*) AS cnt FROM photo GROUP BY album_id'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['album_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Photo counts for the given album ids only.
     *
     * @param list<string> $albumIds
     *
     * @return array<string, int> album uuid => count
     */
    public function countPhotosForAlbumIds(array $albumIds): array
    {
        if ([] === $albumIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT album_id, COUNT(*) AS cnt FROM photo WHERE album_id IN (?) GROUP BY album_id',
            [$albumIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['album_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Child-album counts keyed by parent uuid.
     *
     * @return array<string, int> parent uuid => count
     */
    public function countChildrenGroupedByParent(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT parent_id, COUNT(*) AS cnt FROM album WHERE parent_id IS NOT NULL GROUP BY parent_id'
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['parent_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Child-album counts for the given parent ids only.
     *
     * @param list<string> $parentIds
     *
     * @return array<string, int> parent uuid => count
     */
    public function countChildrenForParentIds(array $parentIds): array
    {
        if ([] === $parentIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT parent_id, COUNT(*) AS cnt FROM album WHERE parent_id IN (?) GROUP BY parent_id',
            [$parentIds],
            [\Doctrine\DBAL\ArrayParameterType::STRING]
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['parent_id']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * Public-album search for the visitor search API.
     *
     * @param array{
     *   q?: string|null,
     *   personName?: string|null,
     *   tagSlugs?: list<string>,
     *   year?: int|null,
     *   from?: string|null,
     *   to?: string|null,
     * } $filters
     *
     * @return array{items: Album[], total: int}
     */
    public function searchPublicPaginated(int $page, int $perPage, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.location', 'loc')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public);

        $q = isset($filters['q']) && \is_string($filters['q']) ? trim($filters['q']) : '';
        if ('' !== $q) {
            $qb->andWhere(
                'LOWER(UNACCENT(a.title)) LIKE :q
                OR LOWER(UNACCENT(COALESCE(a.description, \'\'))) LIKE :q
                OR LOWER(UNACCENT(COALESCE(loc.name, \'\'))) LIKE :q
                OR LOWER(UNACCENT(COALESCE(loc.city, \'\'))) LIKE :q
                OR LOWER(UNACCENT(COALESCE(loc.country, \'\'))) LIKE :q'
            )->setParameter('q', SearchText::likePattern($q));
        }

        $this->applyTakenAtFilters($qb, $filters);

        $personName = isset($filters['personName']) && \is_string($filters['personName']) ? trim($filters['personName']) : '';
        $tagSlugs = $filters['tagSlugs'] ?? [];
        if ('' !== $personName || [] !== $tagSlugs) {
            $photoExists = 'EXISTS (SELECT 1 FROM App\Entity\Photo p WHERE p.album = a';
            if ('' !== $personName) {
                $photoExists .= ' AND EXISTS (
                    SELECT 1 FROM App\Entity\Face f
                    JOIN f.person person
                    WHERE f.photo = p
                    AND LOWER(UNACCENT(COALESCE(person.name, \'\'))) LIKE :personName
                )';
                $qb->setParameter('personName', SearchText::likePattern($personName));
            }
            $j = 0;
            foreach ($tagSlugs as $slug) {
                $param = 'tag'.$j;
                $photoExists .= ' AND EXISTS (SELECT 1 FROM App\Entity\Photo ptag'.$j.' JOIN ptag'.$j.'.tags t'.$j.' WHERE ptag'.$j.' = p AND t'.$j.'.slug = :'.$param.')';
                $qb->setParameter($param, $slug);
                ++$j;
            }
            $photoExists .= ')';
            $qb->andWhere($photoExists);
        }

        $total = (int) (clone $qb)
            ->select('COUNT(DISTINCT a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.title', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param \Doctrine\ORM\QueryBuilder $qb
     * @param array{year?: int|null, from?: string|null, to?: string|null} $filters
     */
    private function applyTakenAtFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
    {
        $year = $filters['year'] ?? null;
        if (\is_int($year) && $year >= 1000 && $year <= 9999) {
            $qb->andWhere('a.takenAt IS NOT NULL')
                ->andWhere('a.takenAt <= :yearTo')
                ->andWhere('COALESCE(a.takenAtEnd, a.takenAt) >= :yearFrom')
                ->setParameter('yearFrom', new \DateTimeImmutable(\sprintf('%04d-01-01T00:00:00Z', $year)))
                ->setParameter('yearTo', new \DateTimeImmutable(\sprintf('%04d-12-31T23:59:59Z', $year)));

            return;
        }

        if (isset($filters['from']) && \is_string($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['from'])) {
            $qb->andWhere('a.takenAt IS NOT NULL')
                ->andWhere('COALESCE(a.takenAtEnd, a.takenAt) >= :from')
                ->setParameter('from', new \DateTimeImmutable($filters['from'].'T00:00:00Z'));
        }

        if (isset($filters['to']) && \is_string($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters['to'])) {
            $qb->andWhere('a.takenAt IS NOT NULL')
                ->andWhere('a.takenAt <= :to')
                ->setParameter('to', new \DateTimeImmutable($filters['to'].'T23:59:59Z'));
        }
    }

    /** @param list<string> $ids */
    private function loadAlbumsOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Album> $albums */
        $albums = $this->createQueryBuilder('a')
            ->andWhere('a.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids))
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($albums as $album) {
            $byId[$album->getId()->toRfc4122()] = $album;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
