<?php

namespace App\Repository;

use App\Entity\Album;
use App\Entity\Photo;
use App\Enum\AlbumVisibility;
use App\Enum\MediaStatus;
use App\Service\SearchText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ->orderBy('p.createdAt', 'ASC')
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
            ->orderBy('p.createdAt', 'ASC')
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
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
}
