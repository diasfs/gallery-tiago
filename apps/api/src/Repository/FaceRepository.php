<?php

namespace App\Repository;

use App\Entity\Face;
use App\Entity\Person;
use App\Entity\Photo;
use App\Service\VectorLiteral;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Face>
 */
class FaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Face::class);
    }

    public function findOneByPhotoAndPerson(Photo $photo, Person $person): ?Face
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.photo = :photo')
            ->andWhere('f.person = :person')
            ->setParameter('photo', $photo->getId(), 'uuid')
            ->setParameter('person', $person->getId(), 'uuid')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return Face[] */
    public function findByPhotoAndPerson(Photo $photo, Person $person): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.photo = :photo')
            ->andWhere('f.person = :person')
            ->setParameter('photo', $photo->getId(), 'uuid')
            ->setParameter('person', $person->getId(), 'uuid')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<string> visible photo ids, closest first
     */
    public function findSimilarVisiblePhotoIds(Uuid $photoId, int $limit = 12, float $maxDistance = 0.45): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT ranked.photo_id
                FROM (
                    SELECT p.id::text AS photo_id, MIN(f2.embedding <=> f.embedding) AS dist
                    FROM face f
                    INNER JOIN face f2 ON f2.has_embedding = true AND f2.photo_id <> f.photo_id
                    INNER JOIN photo p ON p.id = f2.photo_id
                    INNER JOIN album a ON a.id = p.album_id
                    WHERE f.photo_id = :photoId
                      AND f.has_embedding = true
                      AND a.visibility IN ('public', 'unlisted')
                    GROUP BY p.id
                    HAVING MIN(f2.embedding <=> f.embedding) <= :maxDistance
                    ORDER BY dist ASC
                    LIMIT {$limit}
                ) ranked
            SQL,
            [
                'photoId' => $photoId->toRfc4122(),
                'maxDistance' => $maxDistance,
            ],
        );

        return array_map(static fn (array $row): string => (string) $row['photo_id'], $rows);
    }

    /**
     * @return list<array{personId: string, isNamed: bool, distance: float, name: ?string, avatarCropPath: ?string}>
     */
    public function findNearestPeople(array $embedding, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT
                    person.id::text AS person_id,
                    person.is_named,
                    person.name,
                    COALESCE(person.avatar_path, avatar_face.crop_path, nearest.crop_path) AS avatar_crop_path,
                    nearest.dist
                FROM (
                    SELECT DISTINCT ON (face.person_id)
                        face.person_id,
                        face.crop_path,
                        face.embedding <=> :embedding::vector AS dist
                    FROM face
                    WHERE face.has_embedding = true
                      AND face.person_id IS NOT NULL
                    ORDER BY face.person_id, dist ASC
                ) nearest
                INNER JOIN person ON person.id = nearest.person_id
                LEFT JOIN face avatar_face ON avatar_face.id = person.avatar_face_id
                ORDER BY nearest.dist ASC
                LIMIT {$limit}
            SQL,
            ['embedding' => VectorLiteral::format($embedding)],
        );

        return array_map(
            static fn (array $row): array => [
                'personId' => (string) $row['person_id'],
                'isNamed' => (bool) $row['is_named'],
                'distance' => (float) $row['dist'],
                'name' => $row['name'],
                'avatarCropPath' => $row['avatar_crop_path'],
            ],
            $rows,
        );
    }

    public function countUnnamedClustersWithEmbeddings(): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            <<<SQL
                SELECT COUNT(DISTINCT p.id)::int
                FROM person p
                INNER JOIN face f ON f.person_id = p.id AND f.has_embedding = true
                WHERE p.is_named = false
            SQL,
        );
    }

    /**
     * @return array{
     *     items: list<array{
     *         sourcePersonId: string,
     *         targetPersonId: string,
     *         distance: float,
     *         faceCountA: int,
     *         faceCountB: int,
     *         sourceAvatarCropPath: ?string,
     *         targetAvatarCropPath: ?string,
     *     }>,
     *     analyzedClusterCount: int,
     *     truncated: bool,
     * }
     */
    public function findUnnamedMergeSuggestions(float $maxDistance, int $limit = 50, int $maxClusters = 500): array
    {
        $limit = max(1, min(200, $limit));
        $maxClusters = max(1, min(5000, $maxClusters));
        $totalClusters = $this->countUnnamedClustersWithEmbeddings();
        $analyzedClusters = min($totalClusters, $maxClusters);
        $truncated = $totalClusters > $maxClusters;

        if (0 === $analyzedClusters) {
            return [
                'items' => [],
                'analyzedClusterCount' => 0,
                'truncated' => false,
            ];
        }

        $connection = $this->getEntityManager()->getConnection();
        $connection->executeStatement("SET LOCAL statement_timeout = '30s'");
        $rows = $connection->fetchAllAssociative(
            <<<SQL
                WITH ranked_clusters AS (
                    SELECT f.person_id, COUNT(*)::int AS face_count
                    FROM face f
                    INNER JOIN person p ON p.id = f.person_id AND p.is_named = false
                    WHERE f.has_embedding = true
                    GROUP BY f.person_id
                    ORDER BY face_count DESC, f.person_id
                    LIMIT {$maxClusters}
                ),
                reps AS MATERIALIZED (
                    SELECT DISTINCT ON (p.id)
                        p.id AS person_id,
                        f.embedding,
                        COALESCE(p.avatar_path, avatar_face.crop_path, f.crop_path) AS avatar_crop_path,
                        rc.face_count
                    FROM ranked_clusters rc
                    INNER JOIN person p ON p.id = rc.person_id
                    INNER JOIN face f ON f.person_id = p.id AND f.has_embedding = true
                    LEFT JOIN face avatar_face ON avatar_face.id = p.avatar_face_id
                    ORDER BY
                        p.id,
                        CASE WHEN f.id = p.avatar_face_id THEN 0 ELSE 1 END,
                        f.confidence DESC NULLS LAST,
                        f.id
                )
                SELECT
                    pairs.person_a::text AS source_person_id,
                    pairs.person_b::text AS target_person_id,
                    pairs.distance,
                    pairs.face_count_a,
                    pairs.face_count_b,
                    source_rep.avatar_crop_path AS source_avatar_crop_path,
                    target_rep.avatar_crop_path AS target_avatar_crop_path
                FROM (
                    SELECT
                        LEAST(r1.person_id, r2.person_id) AS person_a,
                        GREATEST(r1.person_id, r2.person_id) AS person_b,
                        (r1.embedding <=> r2.embedding) AS distance,
                        r1.face_count AS face_count_a,
                        r2.face_count AS face_count_b
                    FROM reps r1
                    INNER JOIN reps r2 ON r2.person_id > r1.person_id
                    WHERE (r1.embedding <=> r2.embedding) <= :maxDistance
                    ORDER BY distance ASC
                    LIMIT {$limit}
                ) pairs
                INNER JOIN reps source_rep ON source_rep.person_id = pairs.person_a
                INNER JOIN reps target_rep ON target_rep.person_id = pairs.person_b
            SQL,
            ['maxDistance' => $maxDistance],
        );

        return [
            'items' => array_map(
                static fn (array $row): array => [
                    'sourcePersonId' => (string) $row['source_person_id'],
                    'targetPersonId' => (string) $row['target_person_id'],
                    'distance' => (float) $row['distance'],
                    'faceCountA' => (int) $row['face_count_a'],
                    'faceCountB' => (int) $row['face_count_b'],
                    'sourceAvatarCropPath' => $row['source_avatar_crop_path'] ?: null,
                    'targetAvatarCropPath' => $row['target_avatar_crop_path'] ?: null,
                ],
                $rows,
            ),
            'analyzedClusterCount' => $analyzedClusters,
            'truncated' => $truncated,
        ];
    }
}
