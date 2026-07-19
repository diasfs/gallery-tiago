<?php

namespace App\Repository;

use App\Entity\Photo;
use App\Enum\AlbumVisibility;
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
     * @return Photo[]
     */
    public function findVisibleByPersonId(Uuid $personId): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.album', 'a')
            ->andWhere('a.visibility IN (:visibilities)')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Face f WHERE f.photo = p AND f.person = :personId)')
            ->setParameter('personId', $personId, 'uuid')
            ->setParameter('visibilities', [AlbumVisibility::Public, AlbumVisibility::Unlisted])
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
