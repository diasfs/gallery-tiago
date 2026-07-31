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

    /**
     * @return list<array{sourcePersonId: string, targetPersonId: string, distance: float, faceCountA: int, faceCountB: int}>
     */
    public function findUnnamedMergeSuggestions(float $maxDistance, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<SQL
                SELECT
                    pairs.person_a::text AS source_person_id,
                    pairs.person_b::text AS target_person_id,
                    pairs.distance,
                    counts_a.face_count AS face_count_a,
                    counts_b.face_count AS face_count_b
                FROM (
                    SELECT
                        LEAST(f1.person_id, f2.person_id) AS person_a,
                        GREATEST(f1.person_id, f2.person_id) AS person_b,
                        MIN(f1.embedding <=> f2.embedding) AS distance
                    FROM face f1
                    INNER JOIN face f2 ON f2.person_id > f1.person_id
                    INNER JOIN person p1 ON p1.id = f1.person_id AND p1.is_named = false
                    INNER JOIN person p2 ON p2.id = f2.person_id AND p2.is_named = false
                    WHERE f1.has_embedding = true
                      AND f2.has_embedding = true
                    GROUP BY person_a, person_b
                    HAVING MIN(f1.embedding <=> f2.embedding) <= :maxDistance
                    ORDER BY distance ASC
                    LIMIT {$limit}
                ) pairs
                INNER JOIN (
                    SELECT person_id, COUNT(*)::int AS face_count
                    FROM face
                    GROUP BY person_id
                ) counts_a ON counts_a.person_id = pairs.person_a
                INNER JOIN (
                    SELECT person_id, COUNT(*)::int AS face_count
                    FROM face
                    GROUP BY person_id
                ) counts_b ON counts_b.person_id = pairs.person_b
            SQL,
            ['maxDistance' => $maxDistance],
        );

        return array_map(
            static fn (array $row): array => [
                'sourcePersonId' => (string) $row['source_person_id'],
                'targetPersonId' => (string) $row['target_person_id'],
                'distance' => (float) $row['distance'],
                'faceCountA' => (int) $row['face_count_a'],
                'faceCountB' => (int) $row['face_count_b'],
            ],
            $rows,
        );
    }
}
