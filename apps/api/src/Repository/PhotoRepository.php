<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Enum\MediaStatus;
use App\Service\SearchText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Photo>
 */
class PhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Photo::class);
    }

    public function findVisibleById(Uuid $id): ?Photo
    {
        return $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('p.id = :id')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findVisibleByAlbumSlugAndFilename(string $albumSlug, string $filename): ?Photo
    {
        return $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('a.slug = :slug')
            ->andWhere('p.filename = :filename')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('slug', $albumSlug)
            ->setParameter('filename', $filename)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsFilenameInAlbum(Album $album, string $filename, ?Uuid $excludePhotoId = null): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('1')
            ->andWhere('p.album = :album')
            ->andWhere('p.filename = :filename')
            ->setParameter('album', $album)
            ->setParameter('filename', $filename)
            ->setMaxResults(1);

        if (null !== $excludePhotoId) {
            $qb->andWhere('p.id != :exclude')->setParameter('exclude', $excludePhotoId, 'uuid');
        }

        return null !== $qb->getQuery()->getOneOrNullResult();
    }

    /**
     * Atomically increments and returns the new view_count.
     * Safe under concurrent detail-page loads.
     */
    public function incrementViewCount(Uuid $id): int
    {
        $result = $this->getEntityManager()->getConnection()->fetchOne(
            'UPDATE photo SET view_count = view_count + 1 WHERE id = :id RETURNING view_count',
            ['id' => $id->toRfc4122()],
        );

        if (false === $result || null === $result) {
            throw new \RuntimeException(\sprintf('Photo %s not found for view increment.', $id->toRfc4122()));
        }

        return (int) $result;
    }

    /**
     * Uses an EXISTS subquery (rather than joining `Face`) so photos with
     * multiple matching faces aren't duplicated — a plain `DISTINCT` isn't
     * an option here since Postgres can't compare the `thumb_paths` json
     * column for row equality.
     *
     * @return array{items: Photo[], total: int}
     */
    public function findVisibleByPersonIdPaginated(Uuid $personId, int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('a.visibility IN (:visibilities)')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Face f WHERE f.photo = p AND f.person = :personId)')
            ->setParameter('personId', $personId, 'uuid')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);

        $total = (int) (clone $base)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function countVisibleByPersonId(Uuid $personId): int
    {
        return $this->findVisibleByPersonIdPaginated($personId, 1, 1)['total'];
    }

    /**
     * Caller is responsible for having already verified the album's
     * visibility (e.g. via `AlbumRepository::findVisibleBySlug`).
     *
     * @return Photo[]
     */
    public function findByAlbum(Album $album): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.album = :album')
            ->setParameter('album', $album)
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: Photo[], total: int}
     */
    public function findByAlbumPaginated(Album $album, int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('p')
            ->andWhere('p.album = :album')
            ->setParameter('album', $album);

        $total = (int) (clone $base)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('p.sortOrder', 'ASC')
            ->addOrderBy('p.createdAt', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function nextSortOrderForAlbum(Album $album): int
    {
        $max = $this->createQueryBuilder('p')
            ->select('MAX(p.sortOrder)')
            ->andWhere('p.album = :album')
            ->setParameter('album', $album)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max) + 1;
    }

    /**
     * @return array{items: Photo[], total: int}
     */
    public function findVisibleByTagSlugPaginated(string $slug, int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->join('p.tags', 't')
            ->andWhere('t.slug = :slug')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('slug', $slug)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);

        $total = (int) (clone $base)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function countVisibleByTagSlug(string $slug): int
    {
        return $this->findVisibleByTagSlugPaginated($slug, 1, 1)['total'];
    }

    /**
     * @return array{items: Photo[], total: int}
     */
    public function findVisibleByLocationIdPaginated(Uuid $locationId, int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('a.location = :locationId')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('locationId', $locationId, 'uuid')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);

        $total = (int) (clone $base)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function countVisibleByLocationId(Uuid $locationId): int
    {
        return $this->findVisibleByLocationIdPaginated($locationId, 1, 1)['total'];
    }

    /** @return Photo[] */
    public function findWithOriginalAndAvif(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.avifPath IS NOT NULL')
            ->andWhere('p.originalPath IS NOT NULL')
            ->andWhere("p.originalPath <> ''")
            ->getQuery()
            ->getResult();
    }

    /**
     * Failed media only. Callers must still verify a set path is missing on disk.
     *
     * @return Photo[]
     */
    public function findNeedingOriginalRestore(): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->addSelect('a')
            ->andWhere('p.mediaStatus = :failed')
            ->andWhere('p.avifPath IS NULL')
            ->setParameter('failed', MediaStatus::Failed)
            ->orderBy('p.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{media: array<string, int>, faces: array<string, int>, tags: array<string, int>}
     */
    public function countGroupedByProcessingStatus(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        return [
            'media' => $this->countColumn($conn, 'media_status'),
            'faces' => $this->countColumn($conn, 'faces_status'),
            'tags' => $this->countColumn($conn, 'tags_status'),
        ];
    }

    /**
     * @return array{items: Photo[], total: int}
     */
    public function findByProcessingStatus(string $stage, string $status, int $page, int $perPage): array
    {
        $field = match ($stage) {
            'media' => 'p.mediaStatus',
            'faces' => 'p.facesStatus',
            'tags' => 'p.tagsStatus',
            default => throw new \InvalidArgumentException(\sprintf('Invalid stage "%s".', $stage)),
        };

        $qb = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->addSelect('a')
            ->andWhere($field.' = :status')
            ->setParameter('status', $status)
            ->orderBy('p.createdAt', 'DESC');

        $total = (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere($field.' = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Pending media photos that still have an original on disk, oldest first.
     *
     * @return Photo[]
     */
    public function findPendingWithOriginal(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.mediaStatus = :status')
            ->andWhere('p.originalPath IS NOT NULL')
            ->andWhere("p.originalPath <> ''")
            ->setParameter('status', MediaStatus::Pending)
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countPendingWithOriginal(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.mediaStatus = :status')
            ->andWhere('p.originalPath IS NOT NULL')
            ->andWhere("p.originalPath <> ''")
            ->setParameter('status', MediaStatus::Pending)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Photos waiting for a faces/tags worker claim, oldest first.
     *
     * @return Photo[]
     */
    public function findQueuedForStage(string $stage, int $limit): array
    {
        $field = match ($stage) {
            'faces' => 'p.facesStatus',
            'tags' => 'p.tagsStatus',
            default => throw new \InvalidArgumentException(\sprintf('Invalid stage "%s"; expected faces|tags.', $stage)),
        };

        return $this->createQueryBuilder('p')
            ->andWhere($field.' = :status')
            ->setParameter('status', 'queued')
            ->orderBy('p.createdAt', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param \Doctrine\DBAL\Connection $conn
     *
     * @return array<string, int>
     */
    private function countColumn(\Doctrine\DBAL\Connection $conn, string $column): array
    {
        $rows = $conn->fetchAllAssociative(
            \sprintf('SELECT %s AS status, COUNT(*) AS cnt FROM photo GROUP BY %s', $column, $column)
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }

        return $counts;
    }

    /**
     * @param array{
     *   q?: string|null,
     *   personIds?: list<string>,
     *   tagSlugs?: list<string>,
     *   year?: int|null,
     *   from?: string|null,
     *   to?: string|null,
     * } $filters
     *
     * @return array{items: Photo[], total: int}
     */
    public function searchPublicPaginated(int $page, int $perPage, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->leftJoin('a.location', 'loc')
            ->andWhere('a.visibility = :visibility')
            ->setParameter('visibility', AlbumVisibility::Public);

        $q = isset($filters['q']) && \is_string($filters['q']) ? trim($filters['q']) : '';
        if ('' !== $q) {
            $qb->andWhere(
                'LOWER(UNACCENT(COALESCE(p.title, \'\'))) LIKE :q
                OR LOWER(UNACCENT(a.title)) LIKE :q
                OR LOWER(UNACCENT(COALESCE(a.description, \'\'))) LIKE :q
                OR LOWER(UNACCENT(COALESCE(loc.name, \'\'))) LIKE :q
                OR LOWER(UNACCENT(COALESCE(loc.city, \'\'))) LIKE :q
                OR LOWER(UNACCENT(COALESCE(loc.country, \'\'))) LIKE :q'
            )->setParameter('q', SearchText::likePattern($q));
        }

        $year = $filters['year'] ?? null;
        if (\is_int($year) && $year >= 1000 && $year <= 9999) {
            $qb->andWhere('a.takenAt IS NOT NULL')
                ->andWhere('a.takenAt <= :yearTo')
                ->andWhere('COALESCE(a.takenAtEnd, a.takenAt) >= :yearFrom')
                ->setParameter('yearFrom', new \DateTimeImmutable(\sprintf('%04d-01-01T00:00:00Z', $year)))
                ->setParameter('yearTo', new \DateTimeImmutable(\sprintf('%04d-12-31T23:59:59Z', $year)));
        } else {
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

        $i = 0;
        foreach ($filters['personIds'] ?? [] as $personId) {
            $param = 'person'.$i;
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\Face f'.$i.' WHERE f'.$i.'.photo = p AND f'.$i.'.person = :'.$param.')')
                ->setParameter($param, Uuid::fromString($personId), 'uuid');
            ++$i;
        }

        $j = 0;
        foreach ($filters['tagSlugs'] ?? [] as $slug) {
            $param = 'tag'.$j;
            $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\Photo ptag'.$j.' JOIN ptag'.$j.'.tags t'.$j.' WHERE ptag'.$j.' = p AND t'.$j.'.slug = :'.$param.')')
                ->setParameter($param, $slug);
            ++$j;
        }

        $total = (int) (clone $qb)
            ->select('COUNT(DISTINCT p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $qb
            ->orderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<array{year: int, month: int, photoCount: int}>
     */
    public function findPublicTimelineMonths(): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
                SELECT
                    EXTRACT(YEAR FROM COALESCE(a.taken_at, a.created_at, p.created_at))::int AS year,
                    EXTRACT(MONTH FROM COALESCE(a.taken_at, a.created_at, p.created_at))::int AS month,
                    COUNT(p.id)::int AS photo_count
                FROM photo p
                INNER JOIN album a ON a.id = p.album_id
                WHERE a.visibility IN ('public', 'unlisted')
                GROUP BY year, month
                ORDER BY year DESC, month DESC
            SQL
        );

        return array_map(
            static fn (array $row): array => [
                'year' => (int) $row['year'],
                'month' => (int) $row['month'],
                'photoCount' => (int) $row['photo_count'],
            ],
            $rows,
        );
    }

    /**
     * @return array{items: Photo[], total: int}
     */
    public function findPublicTimelinePhotosPaginated(int $year, int $month, int $page, int $perPage): array
    {
        $from = new \DateTimeImmutable(\sprintf('%04d-%02d-01T00:00:00Z', $year, $month));
        $to = $from->modify('last day of this month')->setTime(23, 59, 59);

        $base = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('a.visibility IN (:visibilities)')
            ->andWhere('COALESCE(a.takenAt, a.createdAt, p.createdAt) >= :from')
            ->andWhere('COALESCE(a.takenAt, a.createdAt, p.createdAt) <= :to')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $total = (int) (clone $base)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->addSelect('COALESCE(a.takenAt, a.createdAt, p.createdAt) AS HIDDEN timelineAt')
            ->orderBy('timelineAt', 'DESC')
            ->addOrderBy('p.sortOrder', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Photos from public/unlisted albums whose timeline date (album takenAt,
     * album createdAt, or photo createdAt) falls on the given month/day in years before $beforeYear.
     *
     * @return array{items: Photo[], total: int}
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
            AND EXTRACT(MONTH FROM COALESCE(a.taken_at, a.created_at, p.created_at)) = :month
            AND EXTRACT(DAY FROM COALESCE(a.taken_at, a.created_at, p.created_at)) = :day
            AND EXTRACT(YEAR FROM COALESCE(a.taken_at, a.created_at, p.created_at)) < :year
        SQL;

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(p.id)::int FROM photo p INNER JOIN album a ON a.id = p.album_id WHERE {$where}",
            $params,
            $types,
        );

        $offset = max(0, ($page - 1) * $perPage);
        $ids = $conn->fetchFirstColumn(
            <<<SQL
                SELECT p.id::text
                FROM photo p
                INNER JOIN album a ON a.id = p.album_id
                WHERE {$where}
                ORDER BY COALESCE(a.taken_at, a.created_at, p.created_at) DESC, p.sort_order ASC
                LIMIT :limit OFFSET :offset
            SQL,
            [...$params, 'limit' => $perPage, 'offset' => $offset],
            [...$types, 'limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => $this->loadVisiblePhotosOrdered(array_map(static fn (mixed $id): string => (string) $id, $ids)),
            'total' => $total,
        ];
    }

    /**
     * @return array{items: Photo[], total: int}
     */
    public function findPublicMostViewedPaginated(int $page, int $perPage): array
    {
        $base = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('a.visibility IN (:visibilities)')
            ->andWhere('p.viewCount > 0')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted]);

        $total = (int) (clone $base)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = (clone $base)
            ->orderBy('p.viewCount', 'DESC')
            ->addOrderBy('p.createdAt', 'DESC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<Photo>
     */
    private function loadVisiblePhotosOrdered(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<Photo> $photos */
        $photos = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->addSelect('a')
            ->andWhere('p.id IN (:ids)')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('ids', array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids))
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($photos as $photo) {
            $byId[$photo->getId()->toRfc4122()] = $photo;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $excludeIds
     *
     * @return list<string>
     */
    public function findSimilarVisiblePhotoIdsByTags(Uuid $photoId, int $limit, array $excludeIds = []): array
    {
        $limit = max(1, min(100, $limit));
        $excludeIds = array_values(array_filter($excludeIds, static fn (string $id): bool => '' !== $id));
        $conn = $this->getEntityManager()->getConnection();
        $excludeClause = '';
        if ([] !== $excludeIds) {
            $quoted = array_map(static fn (string $id): string => $conn->quote($id), $excludeIds);
            $excludeClause = 'AND p.id NOT IN ('.implode(',', $quoted).')';
        }

        $rows = $conn->fetchAllAssociative(
            <<<SQL
                SELECT p.id::text AS photo_id, COUNT(shared.tag_id)::int AS shared_tags
                FROM photo source_photo
                INNER JOIN photo_tag source_tag ON source_tag.photo_id = source_photo.id
                INNER JOIN photo_tag shared ON shared.tag_id = source_tag.tag_id AND shared.photo_id <> source_photo.id
                INNER JOIN photo p ON p.id = shared.photo_id
                INNER JOIN album a ON a.id = p.album_id
                WHERE source_photo.id = :photoId
                  AND a.visibility IN ('public', 'unlisted')
                  {$excludeClause}
                GROUP BY p.id
                ORDER BY shared_tags DESC, p.created_at DESC
                LIMIT {$limit}
            SQL,
            ['photoId' => $photoId->toRfc4122()],
        );

        return array_map(static fn (array $row): string => (string) $row['photo_id'], $rows);
    }

    /**
     * @param list<string> $ids
     *
     * @return Photo[]
     */
    public function findVisibleByIdsPreservingOrder(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $uuidIds = array_map(static fn (string $id): Uuid => Uuid::fromString($id), $ids);
        $photos = $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('p.id IN (:ids)')
            ->andWhere('a.visibility IN (:visibilities)')
            ->setParameter('ids', $uuidIds)
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($photos as $photo) {
            $byId[$photo->getId()->toRfc4122()] = $photo;
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
