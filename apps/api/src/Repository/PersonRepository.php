<?php

namespace App\Repository;

use App\Entity\Person;
use App\Service\SearchText;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

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
            ->andWhere('p.deletedAt IS NULL')
            ->orderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param 'all'|'named'|'unnamed'|'trashed' $scope
     *
     * @return Person[]
     */
    public function search(string $scope = 'named', ?string $query = null, int $limit = 100): array
    {
        return $this->searchPaginated($scope, $query, 1, $limit)['items'];
    }

    /**
     * @param 'all'|'named'|'unnamed'|'trashed' $scope
     *
     * @return array{items: Person[], total: int}
     */
    public function searchPaginated(
        string $scope,
        ?string $query,
        int $page,
        int $perPage,
    ): array {
        $base = $this->createQueryBuilder('p');
        $this->applyScope($base, $scope);

        if (null !== $query && '' !== trim($query)) {
            $base->andWhere('LOWER(UNACCENT(p.name)) LIKE :query')
                ->setParameter('query', SearchText::likePattern($query));
        }

        $total = (int) (clone $base)
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $itemsQuery = (clone $base)
            ->leftJoin('p.avatarFace', 'af')
            ->addSelect('af');

        if ('trashed' === $scope) {
            $itemsQuery->orderBy('p.deletedAt', 'DESC')
                ->addOrderBy('p.id', 'ASC');
        } elseif ('named' === $scope) {
            $itemsQuery->orderBy('p.name', 'ASC')
                ->addOrderBy('p.id', 'ASC');
        } elseif ('unnamed' === $scope) {
            $itemsQuery->orderBy('p.id', 'ASC');
        } else {
            $itemsQuery->orderBy('p.isNamed', 'DESC')
                ->addOrderBy('p.name', 'ASC')
                ->addOrderBy('p.id', 'ASC');
        }

        $items = $itemsQuery
            ->setFirstResult(max(0, ($page - 1) * $perPage))
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param string[] $personIds
     *
     * @return array<string, array{faceCount: int, fallbackCropPath: ?string}>
     */
    public function summarizeFacesForPersonIds(array $personIds): array
    {
        if ([] === $personIds) {
            return [];
        }

        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('IDENTITY(f.person) AS personId')
            ->addSelect('COUNT(f.id) AS faceCount')
            ->addSelect('MIN(f.cropPath) AS fallbackCropPath')
            ->from(\App\Entity\Face::class, 'f')
            ->andWhere('f.person IN (:personIds)')
            ->setParameter('personIds', $personIds)
            ->groupBy('f.person')
            ->getQuery()
            ->getArrayResult();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(string) $row['personId']] = [
                'faceCount' => (int) $row['faceCount'],
                'fallbackCropPath' => $row['fallbackCropPath'],
            ];
        }

        return $summaries;
    }

    /** @return Person[] */
    public function searchNamed(?string $query): array
    {
        return $this->search('named', $query, 20);
    }

    /**
     * Exact name match among active named people (case-insensitive).
     */
    public function findOneNamedByName(string $name): ?Person
    {
        $trimmed = trim($name);
        if ('' === $trimmed) {
            return null;
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.isNamed = true')
            ->andWhere('p.deletedAt IS NULL')
            ->andWhere('LOWER(p.name) = :name')
            ->setParameter('name', mb_strtolower($trimmed))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Named people who appear on at least one photo in a public album.
     *
     * @return Person[]
     */
    public function searchNamedPublic(?string $query, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('person')
            ->andWhere('person.isNamed = true')
            ->andWhere('person.deletedAt IS NULL')
            ->andWhere(
                'EXISTS (
                    SELECT 1 FROM App\Entity\Face f
                    JOIN f.photo p
                    JOIN p.album a
                    WHERE f.person = person AND a.visibility = :visibility
                )'
            )
            ->setParameter('visibility', \App\Enum\AlbumVisibility::Public)
            ->orderBy('person.name', 'ASC')
            ->setMaxResults($limit);

        if (null !== $query && '' !== trim($query)) {
            $qb->andWhere('LOWER(UNACCENT(person.name)) LIKE :query')
                ->setParameter('query', SearchText::likePattern($query));
        }

        return $qb->getQuery()->getResult();
    }

    public function findIncludingTrashed(string $id): ?Person
    {
        return $this->find($id);
    }

    public function findActive(Uuid|string $id): ?Person
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.deletedAt IS NULL')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param 'all'|'named'|'unnamed'|'trashed' $scope
     */
    private function applyScope(QueryBuilder $qb, string $scope): void
    {
        if ('trashed' === $scope) {
            $qb->andWhere('p.deletedAt IS NOT NULL');

            return;
        }

        $qb->andWhere('p.deletedAt IS NULL');

        if ('named' === $scope) {
            $qb->andWhere('p.isNamed = true');
        } elseif ('unnamed' === $scope) {
            $qb->andWhere('p.isNamed = false');
        }
    }
}
